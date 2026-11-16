# Stock name check: the Assistant's helper for crate prices, Import and Menu links

Owner, 2026-11-17: *"Lets do this then, use fable logic so we dont mess up and also use it for the imports wizard maybe and
maybe even the menu wizard just as a helper of its own. Without it on it works still like this and efficiently but with AI
we harness more swarming leads to perfection."*

Status: SPEC + Fable 5.2 check GO-WITH-CHANGES (2026-11-17; the 9 changes are folded in below, each marked (Fable 2026-11-17)). Build order in §6. Phase 1 = engine + Fix crate prices + the notice-card fix.
Code was read in the Liwonde repo (the two repos are identical for these files). Rosalyn's is LIVE. All DB tests,
recordings and rehearsals run at **Liwonde only**. Nothing in this spec writes stock, costs, recipes or money.

---

## 0. Why (evidence)

- The import stored crate prices as the cost of ONE bottle at Rosalyn's: 171 bottle/can items worth about K209M, for example
  Kuchekuche 41,322.31, Red Bull 168k, Heineken 110k and Coke 15k.
- `includes/import/case-signals.php` (`import_cs_judge`) uses only this hotel's own data: a recipe-linked selling price,
  deliveries, the item's own pack size and the sheet's value cell. Rosalyn's has none of these yet, and the category/unit
  cluster cannot separate beers from spirits ("spirits next to beers have no bulk"). It flagged 0 of the 25 real cases.
- Product names must not be hard-coded (rule "No hard-coded hotel facts"). Reading a name as a *kind of product* is
  general knowledge, which is exactly what a language model adds. It is the missing evidence source.

## 1. What it adds, and what it never does

**Adds** (only when the helper is ON for the hotel AND the person passes the gate in D3):
1. It reads each item's **product type** from its name, category and unit (closed list in D5). It flags a cost that is
   implausible for ONE unit of that type at this hotel's price level.
2. It suggests the **pack size N** (crate/case of N) with a short reason and a confidence level.
3. Menu only: it suggests the **amount per sale** for a linked stock item (one whole bottle/can, or one bar measure of a bottle).

**Never**: it never writes stock, costs, batches, purchase orders, recipes, prices or import answers. It never pre-answers an
Import question. It never fills a Fix crate prices box without a person's click. It never says an item is "fine", "correct"
or "no problem". With the helper OFF, every page renders and behaves byte-for-byte as today (test `sk_off_identical`).

---

## 2. Decisions (numbered; each has its rail and its test)

Tests live in `scripts/tests/ai_assistant_test.php` (own file, fake provider through `rh_ai_set_transport`, no network,
rolls back / cleans up, Liwonde DB only). The hooks' own tests live in each lead's test file (§6).

### D1. One engine file, one endpoint, one browser script
- `includes/ai/ai-stock-kinds.php` (prefix `rh_ai_sk_*`): gate, payload, prompt, model run, validation, agreement, cache,
  teaching, combination (pure), wording (local templates), menu amount maths.
- `admin/api/ai-stock-helper.php`: a JSON endpoint (POST, session + CSRF + `X-Requested-With`, the same pattern as
  `admin/api/ai-attention-note.php`). The browser sends **only ids** (surface, job id, item ids, menu item ids). The server reads
  every name, cost and price itself from the DB or the import job. Nothing the browser sends is passed to a model.
- `admin/js/ai-stock-helper.js`: one shared mount. It does nothing unless the page printed the mount element (D3), so a page
  that includes it while the helper is off shows nothing.
- Rail: the endpoint has no SQL write outside `ai_stock_kinds`, `ai_usage_daily`, the breaker/incident tables and (Import
  only) the import lead's `import_job_set_ai_case()` (D9). Test `sk_endpoint_no_stock_writes`: row counts and checksums of
  `stock_ingredients`, `stock_batches`, `purchase_order_items`, `stock_recipe_ingredients`, the menu tables and `import_jobs.analysis_json`
  are the same before and after each action.

### D2. Kill switches (fail-closed; any one stops it)
1. New setting `ai_stock_helper_enabled`, default `'0'`. A missing row counts as OFF. It **ships OFF at both hotels**, and the owner
   switches it on at Liwonde first.
2. `rh_ai_enabled()` (master switch + `ai_assistant` module + licence + pause). `moduleEnabled()` is never used.
3. `ai_cloud_enabled` = 1 and `rh_ai_no_spend_blocks()` false (the `NO_AI_SPEND` shell hook means CLI never spends).
4. The breaker `rh_ai_breaker_is_open($db, 'stock_kinds')`: 3 errors in 10 minutes switch it off for one hour
   (`RH_AI_BREAKER_*`).
5. `rh_ai_budget_problem()` for the person (the runaway guard). The monthly aim stays an aim and is never a stop (owner 2026-10-28).
- Rail: any switch off means **no model call and no mount element**. Cached results are not shown either, because the helper is
  simply absent. Tests `sk_switch_default_off`, `sk_master_off_no_call`, `sk_no_spend_env_no_call`, `sk_breaker_trips`.

### D3. Gate, per surface (server side, every request)
`rh_ai_sk_gate(PDO $db, int $uid, string $surface, array $ref = []): bool` mirrors `rh_ai_an_gate()`: chat tables ready,
`ai_assistant_use`, cloud on, provider key or fake, a Fast or Primary model, then D2, then the surface's own rule:
| surface | extra rule (the page's own permission, unchanged) |
|---|---|
| `fix` (Stock > Fix crate prices) | `stock_management` (the page's permission) |
| `import` | `rh_import_can_use($uid)` + the job is visible to the person (the `di_job()` rule: own job, or admin sees all) |
| `menu` (Menu Management) | page permission `menu` + module `pos` + the stock-link permission the recipe API already checks |
- Two kinds of gate, kept apart: the **show/run gate** above is per person. The **apply gate** used inside Import resolve
  (D9) is hotel-wide and **DB state only**: `ai_stock_helper_enabled` = 1 and `rh_ai_enabled()`. Cloud-on and no-spend are left out, because applying a stored snapshot spends nothing and `NO_AI_SPEND` is true in every shell. Those two only gate *running* a check. So web, CLI and every viewer see the same questions, and the import suite can test the ON path.
- Tests `sk_gate_per_surface`, `sk_gate_job_not_visible`, `sk_gate_no_perm_no_mount`.

### D4. Inputs: what is sent (no personal data, by construction)
This follows the attention-note route: only product facts are sent, so there is nothing to tokenise and the vault is not used
(the vault needs a conversation; conversation 0 is never used).
- Per item, one compact line: `no | name | category | unit | cost | pack name/size if stored`. Here `no` is a local index
  (1..n), never a DB id.
- List context: the currency **from settings** (`currency_symbol`, plus the currency code if a setting holds it), and up to 60
  **anchor prices**: this hotel's bar/drink menu items (name and selling price), from the menu tables. These give the model
  the hotel's price level instead of its memory of exchange rates.
- Import only: the sheet name (e.g. "BAR STOCK") is sent as a category when the row has none.
- **Never sent**: notes, supplier names, people, barcodes, quantities on hand, stock values, PO numbers, user names, the
  file name.
- Name guard (before sending): an item whose name holds an email, a 9+ digit run, a booking reference (the vault's patterns), or a
  known person's **full** name (two or more words from `rh_ai_vault_people()`; single words never block, because product names
  share words with people) is **not sent**. It counts as "not checked by name". Confirm the shape of `rh_ai_vault_people()` at build.
- All names are untrusted data: they are wrapped as quoted data in the user message, and the frozen prompt says to never follow
  text inside them. The output can only be the D5 fields, so an injected instruction cannot do anything.
- (Fable 2026-11-17) Names that read as instructions are not sent: longer than 80 characters, more than 12 words, or holding words such as
  "ignore", "instruction", "prompt", "system", "assistant", "reply", "json", "respond". They count as "not checked by name".
- (Fable 2026-11-17) An item whose stock batches still holding stock have **different unit costs** (more than 2% apart) gets a flag only, never an
  N or a chip, because dividing one cost by N would be wrong for the other batches. Shown: "Its batches have different costs: check each batch."
- Tests `sk_payload_fields_only`, `sk_payload_skips_person_names`, `sk_payload_injection_name`. `sk_payload_skips_instruction_names`, `sk_mixed_batches_flag_only`.

### D5. Outputs: the exact JSON schema (per model reply)
```
{"items":[{"i":12,"t":"beer","one":false,"n":24,"c":"high","why":"lager bottle; cost fits a crate"}]}
```
| key | type | rule |
|---|---|---|
| `i` | integer | a sent index; unique; any other value means the entry is dropped |
| `t` | string | one of: `beer`, `cider`, `soft_drink`, `energy_drink`, `water`, `juice`, `mixer`, `spirit`, `wine`, `sparkling_wine`, `liqueur`, `other_drink`, `food`, `non_food`, `unknown` |
| `one` | boolean | true = the cost is plausible for ONE unit as written in Unit; false = it is not |
| `n` | integer or null | only when `one` is false: units in one crate/case |
| `c` | string | `high` / `medium` / `low` |
| `why` | string | at most 8 words, no figures |

Menu calls add one key, `s` (`whole` / `measure`). The bottle volume is **parsed locally** from the stock name (regex `(\d+(?:\.\d+)?)\s*(ml|cl|l|litre)`), never taken from the model. The measure kind is derived locally from `t` (spirit or liqueur gives tot; wine or sparkling wine gives glass), and its size is a hotel setting (D12).

(Fable 2026-11-17) Volume regex: word boundaries, case-insensitive, `\b(\d+(?:\.\d+)?)\s?(ml|cl|l|ltr|litre|liter)\b`, result 50-5000 ml, and
exactly ONE match in the name (two volumes, e.g. "6 x 330ml 2l", give no volume).

### D6. Validation (server; anything invalid becomes "no suggestion", never a guess)
- Strict JSON. Code fences are stripped, then the outer `{...}` is taken, the same as `rh_ai_menu_read_proposal()`.
- `n`: a JSON integer, or a number with no fraction, from **2 to 100**. Anything else (0, 1, 101, 7.5, "24", negative) is set to null.
- `t` not in the list becomes `unknown`, which counts as `low`.
- `c` = `low`, or `t` = `unknown`: the vote is **dropped** (no flag, no N).
- `one` = false with `n` null: the vote can flag but carries no N.
- `why`: cut to 80 characters and limited to letters, spaces and `,;:-'`. If it holds any digit other than its own `n`, it is
  dropped, and the wording is then built only from local templates (D11).
- Entries for indexes that were not sent, and duplicate indexes, are dropped. Sent items with no entry count as "not checked".
- A **truncated** reply (cut off by the token cap) still keeps every complete `{...}` entry before the cut. The rest count as
  "not checked". The reply is not thrown away whole.
- A reply where more than 30% of the parsed entries are invalid means the **model failed** for this run (a breaker strike).
  Its votes are not used.
- Tests `sk_parse_valid`, `sk_parse_n_range` (0, 1, 2, 100, 101, 7.5, "24"), `sk_parse_low_dropped`, `sk_parse_unknown_type`,
  `sk_parse_bad_index`, `sk_parse_truncated_partial`, `sk_parse_mostly_invalid_fails`, `sk_parse_why_with_figures`.

### D7. Batching, cache and teaching
- **One call per list per model**, not one per item. One call takes at most `ai_stock_max_items` items (default 200). 171 items means **one call per model**. A longer list is split into chunks of 200, and D6 keeps whatever a
  truncated reply completed.
- (Fable 2026-11-17) **One chunk per request, capped per run**: the endpoint handles one chunk (at most `ai_stock_max_items`) per request and returns
  "more to check". The browser asks again until done, at most `ai_stock_max_chunks` (default 5) requests per run. Each request stays
  well inside a 60 s gateway, and a cut request loses one chunk only.
- **Cache**: a new table `ai_stock_kinds`, one per hotel (each hotel has its own DB):
  `id, name_key CHAR(64)` (sha256 of `rh_ssi_key(name) . '|' . lower(trim(unit))`), `name_norm VARCHAR(190), unit VARCHAR(30),
  type VARCHAR(20), sale VARCHAR(8) NULL, unit_ml INT NULL, pack_n SMALLINT NULL, judged_cost DECIMAL(14,4) NULL,
  one_unit TINYINT NULL, conf VARCHAR(6), agree VARCHAR(6)` (`both`/`single`/`split`), `source VARCHAR(6)` (`model`/`person`),
  `models VARCHAR(120), person_id INT NULL, why VARCHAR(80), updated_at DATETIME, expires_at DATETIME NULL`, UNIQUE (`name_key`, `source`).
  Product names are not personal data, so the table needs no vault.
- **A cache hit sends nothing**. A model row is reused when the cost is within 0.5% of `judged_cost`. After a fix, a cost of
  `judged_cost / pack_n` (within 0.5%) reads locally as "fits one", with no call. Any other change in cost sends the item again.
  Model rows expire after `ai_stock_cache_days` (default 180). Person rows never expire.
- **A person's answer overrides and teaches**, but only for admin or manager (`rh_ai_correction_hotel_wide()`). Other people's
  answers count for their own run but are not stored. A person row always beats a model row, and the model never overwrites a
  person row. Taught by:
  - (a) Confirm on Fix crate prices with N: crate at the old cost, `pack_n` = N.
  - (a) the row link "This is the price of one": one unit at this cost.
  - (a) "Fill from list" CSV rows marked Y (per-unit). Rows with N teach only at Confirm.
  - (b) an Import answer on a case-price question: `per-case` + N, or `per-unit`.
  - (c) Menu Save with a suggested amount kept (type and sale kept as knowledge only).
- (Fable 2026-11-17) **A cached row (person or model) counts only while the current cost is nearer `judged_cost` than `judged_cost / N`**
  (compared on a log scale). Otherwise the item is checked again. A row with no N counts only within 0.5% of `judged_cost`.
  Model rows also stop counting when the voters' model ids change (the row keeps the ids it was judged with).
- (Fable 2026-11-17) A person's **"price of one"** row never silences a model crate flag. The item is still sent to the voters, and if they say
  crate the row shows "Check this one: <name> said this is the price of one on <date>", **with no chip**. AI Settings lists every
  taught row (item, what was taught, who, when) with a **Remove** button (admin/manager).
- Rail: a taught row **only ever suggests** (same as an agreed model suggestion, D10). It never pre-answers or pre-fills.
  Learned knowledge changes only knowledge and wording, never tools, permissions, limits or the confirm step.
- Tests `sk_cache_hit_no_call`, `sk_cache_cost_change_resends`, `sk_cache_after_fix_local`, `sk_person_beats_model`,
  `sk_teach_admin_manager_only`, `sk_model_never_overwrites_person`.

### D8. Model routing, the two-model check ("swarm"), timeouts and cost
- **Voter A** = the **Fast** role model. **Voter B** = the cheapest *other* ticked model: lowest output price, then lowest input price, then role order Fallback, Extra, Primary (today Fallback; Fallback and Primary cost the same).
  If B is missing or has the same model id, the run is single-model (D10 single rules).
  Today at Liwonde: Fast `gemini-3.5-flash-lite` (US$0.30 / 2.50 per M tokens in/out), Fallback `gemini-3.6-flash` (0.75 / 3.75),
  Primary `gemini-3.8-flash` (0.75 / 3.75). Read with one read-only query on 2026-11-17; prices are always read from `ai_models` at run time.
- Both voters run **in parallel** through `rh_ai_chat_multi()` (curl_multi; sequential under the fake provider). The chat swarm
  (`rh_ai_swarm_consult`) is not used: that is chat-lead plumbing with tools. The new setting `ai_stock_two_models`
  (default `'1'`) is **independent of `ai_swarm_models`** (which stays `'0'`). A list run is rare and costs cents, while the chat swarm
  runs on every question. (Open question Q1.)
- Request: frozen system prompt (cache-friendly; only the list changes), `effort` = `minimal`
  (`rh_ai_gemini_thinking` maps this to `thinkingLevel: minimal` for Gemini 3.x; minimal thinking is still billed as output, so
  the estimate below allows 30% for it), `max_tokens` = items × 45 + 300, `timeout` = `ai_stock_timeout_s` (default 45; the
  provider layer clamps it to 10-60). **No retry and no fallback model**: a voter that fails or times out simply does not vote. The sequential path of
  `rh_ai_chat_multi` goes through `rh_ai_chat_resilient`, which retries once on a timeout. So I add a request flag `no_retry` there
  (an AI file I own), and `ai_stock_timeout_s` is kept at most 45.
- The endpoint calls `session_write_close()` before the model call, so other tabs are not blocked. It sets `set_time_limit(2 × timeout + 10)`. If the host's gateway cuts the request first (often about 60 s), the browser shows
  "The name check did not finish. The list works as before." Nothing is lost: any voter that finished has already written its rows to the cache,
  and the rules apply as today.
  If both voters fail: "The name check did not finish. The list works as before." Rules only, and a breaker strike.
- Usage is recorded per call with `rh_ai_record_usage()` (the person's id; evals use 0).
- **Cost of one full run of 171 items** (input about 6k tokens per call set: 171 lines × about 20 tokens, plus the prompt and up to
  60 menu anchors; output about 30 tokens per item, plus 30% thinking, about 6.7k):
  | run | in | out | US$ |
  |---|---|---|---|
  | Voter A, flash-lite | 6k × 0.30/M = 0.0018 | 6.7k × 2.50/M = 0.0168 | **0.019** |
  | Voter B, 3.6-flash | 6k × 0.75/M = 0.0045 | 6.7k × 3.75/M = 0.0251 | **0.030** |
  | **Both voters** | | | **about US$0.05 for the whole list** |
  Later runs send only uncached names, so a repeat visit costs **US$0**. A 10-row import costs under US$0.005. Against the K209M
  error this run would have caught, the second voter is justified: it is what lets a box be offered as "agreed" (D10).
- Tests `sk_two_voters_parallel`, `sk_single_when_same_model`, `sk_voter_timeout_no_vote`, `sk_both_fail_rules_only`,
  `sk_usage_recorded`, `sk_max_tokens_sized`.

### D9. How it combines with `case-signals` (Import): rules first, AI never pre-answers
**The choice:** decisive evidence from this hotel's own data keeps deciding. AI is one more source that can **raise a question,
add a pack hint, or withdraw a pre-answer** (make it a question). It can **never create a pre-answer** or remove a question.

Truth table (per row; "AI crate" = the agreed or single-high result from D10):
| rules (`import_cs_judge`) | AI says | result |
|---|---|---|
| `case` + own pack (pre-answer today) | crate or nothing | **pre-answer stays** (today). With AI agreeing, its line is added to the reason |
| `case` + own pack | agreed **one unit**, both votes high | **pre-answer withdrawn, the question is asked**, both reasons shown |
| `ask` | crate, with N | question stays; `pack_hint` = AI N **only if** the rules have none; the option label shows N |
| `ask` | one unit | question stays (AI never removes a question) |
| null (silent, the Rosalyn's case) | crate, or split | **a case-price question is added**: same type, options and stable key as today. No pre-answer. Today's rule stays: an existing item without a Cost/Pack tick gets no question |
| null | one unit / nothing | nothing (as today) |

**Determinism:** the AI results are a **snapshot on the job**, not a live lookup. After a check, the endpoint calls the import
lead's new `import_job_set_ai_case(PDO, int $jobId, array $map, int $uid)`. It writes the reserved key `answers_json['_ai_case']`
(same pattern as `_updates`, `_overrides`, `_heal_undo`), with the import's own status checks. The map key comes from ONE shared helper
that the import lead owns, `import_case_row_key(array $row)`: `rh_ssi_key(file name as written) . '|' . unit canon . '|' . round(price, 4)`.
It is built from the row's **file name as written**, before any typo answer (`use:`) swaps the name, so the endpoint and resolve
always compute the same key. Each value: `{v: crate|one|split, n, c, agree, why, t, at}`.
`import_case_evidence_pass()` reads `$answers['_ai_case']` and applies it only while the **hotel-wide apply gate** holds
(`rh_ai_sk_apply_on($pdo)`: DB state only, `ai_stock_helper_enabled` = 1 and `rh_ai_enabled()`, no user, no cloud or no-spend check). So resolve stays pure, every viewer and CLI sees the same questions,
the memo key (analysis + answers md5) changes when the snapshot changes, and switching the helper OFF returns the import to
today's exact questions.

Import lead's exact change in `import_case_evidence_pass()` (resolve.php, the `$j === null → continue` branch and the `$pre` line):
1. Look up `$ai = rh_ai_sk_row_case($answers, $key)` (pure; returns null when the apply gate is off or the key is absent).
2. `[$j, $pre] = rh_ai_sk_combine($j, $ai, $pre)` (pure; implements the table above; it may turn `$pre` into null but never sets it).
3. When `$j` is still null, `continue` as today. Otherwise go down **today's path unchanged** (find or add the question, the unticked-existing skip, `case_evidence`, `pack_hint`).
4. `case_evidence` gains `ai: {v, n, c, agree}` for display only. `q['pre']` is never written from `$ai` (test).
5. (Fable 2026-11-17) `rh_ai_sk_combine()` returns verdict `ask` whenever AI changes anything. Resolve **recomputes `$pre` after the combine step**
   (today `$pre` is set at resolve.php:329 straight after the judge; it moves below the combine call).
6. (Fable 2026-11-17) `import_job_set_ai_case()` re-reads the job **under a row lock** (`SELECT ... FOR UPDATE` in a transaction) and merges **only**
   `_ai_case` into the stored answers, so an answer saved at the same moment is never lost.
- Tests `sk_combine_table` (every row above), `sk_no_pre_from_ai`, `sk_ai_withdraws_pre_only_high_both`,
  `sk_snapshot_same_for_all_viewers`, `sk_apply_gate_off_identical`, `sk_apply_gate_cli_same_as_web`, `sk_key_stable_after_typo_answer`.

### D10. Agreement rules (two voters → one result per item)
| votes (after D6) | result | Fix crate prices (a) | Import (b) |
|---|---|---|---|
| both `one:false`, same N, both ≥ medium | **agreed crate N** | flag + "Use 24" chip, included in "Use all suggested" | question + N as pack hint + "Use crate of 24" chip |
| both `one:false`, N differs or one null | **crate, size unclear** | flag + both sizes as chips; **not** in "Use all" | question, no hint, both sizes named |
| one says crate (≥ medium), the other one unit | **split** | "Check this one" line, no chip | question: "The name does not settle it" |
| both `one:true` | nothing | nothing (never shown as "fine") | nothing |
| single voter (B missing/failed/same id): `one:false` high | **single crate N** | flag + chip; **not** in "Use all" | question + chip |
| single voter: medium | flag only | flag only, no N | question, no N |
| any low/invalid | dropped | (as if not asked) | (as if not asked) |
- A taught **person** row acts as "agreed" and wins over any model result.
- (Fable 2026-11-17) Only an **agreed** N may become an Import `pack_hint`. A single high vote gets a chip only.
- (Fable 2026-11-17) When the rules (`import_cs_judge`) carry any `against` (a cost at or under the selling price, or in line with deliveries), there
  is **no chip and no hint**, only the flag line with both reasons. The Fix page runs `import_cs_judge` too (through `import_cs_stock_flags`),
  and an item with against-evidence is never in "Use all agreed suggestions".
- (Fable 2026-11-17) With fewer than 5 menu anchor prices nothing is "agreed" (the best result is "size unclear"), because the price level is unknown.
- Rail: infer the obvious case (agreed), ask when unclear (split or single), and never decide.
- Tests `sk_agree_both`, `sk_agree_n_split`, `sk_agree_verdict_split`, `sk_single_caps`, `sk_person_row_as_agreed`.

### D11. How a suggestion is shown (plain words; Preview + Confirm stay)
- No sparkle, wand or robot icons and no "smart" labels. Existing design tokens only. The source is named plainly as
  "Suggested by the Assistant". **Every figure in the wording is computed locally** (cost, cost ÷ N, N from the validated vote).
  The model's `why` is added in brackets only if it passed D6.
- Templates (in the engine, used by all three surfaces):
  - agreed: `Reads as a beer. K41,322.31 is high for one bottle; it looks like the price of a crate of 24 (K1,721.76 each). Check the crate size on the invoice.`
  - size unclear: `Reads as a beer. K41,322.31 looks like a crate price. Crate of 12 or 24? Check the invoice.`
  - split: `The name does not settle whether K41,322.31 is one bottle or a crate. Check the invoice.`
  - summary (never an all-clear): `Checked 171 names: 27 look like crate prices, 3 need a look, 140 gave no sign from the name (the price itself was not checked), 1 was not checked.`
- A helper line under the column: `Suggested from the item's name and this list's prices. Nothing changes until you Preview and Confirm.`
- **Fix crate prices (a), the money rail made concrete:** the N box is **never filled by the helper**. A person fills it by
  clicking a row chip ("Use 24") or the button **"Use all agreed suggestions (N)"** (agreed rows only, D10). A box filled that
  way carries `data-src="suggested"` and posts `src: "suggested"` in its line. Preview lists those lines in their **own group**,
  "From suggestions: check each crate size", above the typed ones. The maths is unchanged; `src` is display only, and the server
  ignores it for every calculation. Confirm is unchanged. Typed and CSV values always win over a suggestion (a chip never
  overwrites a non-empty box).
- Tests `sk_wording_figures_local`, `sk_wording_no_absence_claim` (no "fine", "correct", "no problem", "all clear", "none"
  without a checked total), `sk_no_icons_words` (no "AI", "smart", "magic", no sparkle/wand/robot classes in the output HTML of the mount).

### D12. Menu Management (c): the helper's own scope
- **In scope:** one button on the Menu Management page, **"Check stock links"** (one call per list): every drink menu item that
  has a stock link. Per link the engine returns type and `s` whole/measure. The amount is worked out locally:
  - whole: **1** stock unit;
  - measure: `measure_ml ÷ unit_ml`, rounded to 4 dp, using `ai_stock_tot_ml` (seeded 25) for spirits and liqueurs, and
    `ai_stock_glass_ml` (seeded 175) for wine or sparkling wine sold by measure. The measure kind comes from `t` (D5), and `s` says whether the menu item sells the
    whole unit ("Bottle of ...") or a measure.
  It reuses the `rh_ai_menu_unit*`, `rh_ai_menu_pack` and `rh_ai_menu_qty` maths. The volume is parsed locally from the stock name when it is
  written there ("750ml", "75cl", "1L"). A stock item whose unit is already ml or litre uses `rh_ai_menu_unit_factor` with no bottle maths. If it is not in the name, the suggestion says "assumes a 750 ml bottle: check" (conf medium), and the 750 is
  a seeded setting `ai_stock_bottle_ml` (owner can change it), not code.
  In the item modal, a link whose amount differs from the suggestion shows: `Suggested by the Assistant: 0.0333 bottle (one 25 ml tot of a 750 ml bottle). Use this`.
  It is a person's click, then **Save as today**.
- (Fable 2026-11-17) "Whole = 1" only for single-container units (bottle, can, each, piece, unit, sachet, packet). Any other unit gives no suggestion.
- (Fable 2026-11-17) "Use this" appears only when **both voters agree on `t` and `s`**. A suggestion that assumes 750 ml is **text only** (no "Use this").
- Food items: no amount suggestion (portions are a kitchen decision; `rh_ai_menu_propose` already exists for recipes).
- **Out of scope here:** the "stock cost per sale is above the selling price" warning. That is plain arithmetic, useful with
  the Assistant off, and belongs to the restaurant lead as a non-AI patch (Q2). Until then the helper adds a cost line to a
  suggestion only when it computes one: `At this amount one sale uses K1,377 of stock and sells for K3,000.`
- Tests `sk_menu_amount_whole`, `sk_menu_amount_tot`, `sk_menu_amount_glass`, `sk_menu_ml_from_name`, `sk_menu_ml_default_flagged`, `sk_menu_unit_ml_or_litre`,
  `sk_menu_sizes_from_settings`.

### D13. Never claims absence; evals and the completion guardrail
- An item is only ever "flagged" or "not flagged by name". The summary always gives the checked total and the not-checked
  count (D11). The helper never adds a sentence about the price itself being right.
- **Synthetic ground truth (committed):** in `ai_assistant_test.php`, about 40 **generic** names (no hotel's names or prices): lager, cider,
  cola can, tonic, energy drink, still water, single malt, gin, vodka, dry red wine, sparkling wine, cream liqueur, plus food
  and non-food. Each has a crate-priced and a unit-priced cost, paired with recorded fake replies. These include bad replies
  (N 0/101/7.5, low conf, missing items, truncation, a name holding an instruction, the two voters disagreeing). Test `sk_ground_truth_synthetic`.
- **Real ground truth (never committed):** the owner fills `Rosalyns crate sizes 2026-11-17.csv` first. On 2026-11-17 it has
  **0 N and 0 Y filled**, so the 25 crate items are **not** derived from category names. In-app at **Liwonde**, AI Settings →
  **"Crate-price name test"** uploads that CSV (Item, Category, Unit, Cost, N, Y columns; the parser reuses `rh_scf_csv_prefill`
  header matching), runs both voters once in eval mode (`rh_ai_eval_mode`, usage uid 0) and reports:
  - **recall:** crate rows flagged (agreed or unclear) ≥ 92% of the rows the owner marked with an N;
  - **pre-fill safety** (Fable 2026-11-17): an agreed N that differs from the owner's N = **0**, and **0** rows marked Y (per bottle) given an agreed crate chip;
  - **noise:** split/unclear on Y rows ≤ 3.
  The raw model replies are stored **in the Liwonde DB** (`ai_tool_cache`, key `sk_eval|<model>|<sha>`, no expiry), **never in
  files** (they hold Rosalyn's product names and the repo is public). Test `sk_replay_recorded` (Liwonde DB) replays them through
  the fake transport. It is skipped with a plain line when no recording exists.
- (Fable 2026-11-17) The name test runs with the **target hotel's own anchors** (its menu prices, as the live helper would) and reports how many
  anchors it had. With fewer than 5 anchors nothing counts as "agreed" (D10). A **skipped replay is not a clean run** for the
  completion count.
- **Nightly:** the existing nightly eval runs `sk_replay_recorded` + `sk_ground_truth_synthetic` (no model spend). A live re-run
  happens only when the Fast/Fallback model changes (the existing model-upgrade check), costing about US$0.05 each time.
- **Completion:** this helper's eval group must have 14 clean daily runs before `ai_feature_state` can (re)turn `complete`.
  A wrong agreed suggestion that someone reports becomes a regression row in the eval set (name, unit, cost, truth) and a
  fault via `rh_ai_fault_add()`. Code is never patched by the helper itself.

### D14. Settings (seeded by the migration, defaults in `rh_ai_setting_defaults()`; shown in AI Settings with a plain helper line each)
`ai_stock_helper_enabled` 0 · `ai_stock_two_models` 1 · `ai_stock_max_items` 200 · `ai_stock_max_chunks` 5 · `ai_stock_timeout_s` 45 · `ai_stock_cache_days` 180 ·
`ai_stock_anchor_max` 60 · `ai_stock_tot_ml` 25 · `ai_stock_glass_ml` 175 · `ai_stock_bottle_ml` 750.
No permission keys are added. The helper uses `ai_assistant_use` plus each page's own permission.

---

## 3. Fix crate prices (a): the hook contract
Written against stable anchors, not line numbers, because the ui-lead is restyling the page into Type → Check → Save.
On all three surfaces, the mount element and its `<script>` sit **inside** `#rh-admin-page` / `<main>` (the SPA loader drops markup
outside its container).
1. After the page computes `$scf_items`, guarded by `function_exists('rh_ai_sk_gate')` and the gate:
   `rh_ai_sk_mount('fix', $scf_items)`. This prints one hidden mount element plus the cached results for these ids (no call). Off: prints nothing.
2. Each grid row carries `data-scf-id` and its N input `data-scf-n`. The shared JS paints into a row cell with `data-scf-ai`
   (an empty cell is invisible).
3. Line JSON: pass `src` through to the preview rows for grouping only (`rh_scf_lines_from_json` keeps `src ∈ {typed, csv, suggested}`,
   with typed as the default). The preview groups by it. **No maths change.**
3b. (Fable 2026-11-17) `src = suggested` is written into the `stock_cost_correction` log line ("crate size from a suggestion, checked by the person"),
   so System Logs show which corrections came from a suggestion.
4. After `rh_scf_apply()` returns ok: `rh_ai_sk_learn_fix($pdo, $user, $rows)`, which teaches N at **Confirm only** (a filled box can still be edited or abandoned). After a CSV fill: `rh_ai_sk_learn_csv(...)`, which teaches **Y rows only**
   (guarded; does nothing when off).
5. The button "Check names for crate prices" (from the JS mount) posts `action=check&surface=fix`. The server reads the grid itself.

## 4. Import (b): the hook contract
0. `includes/import/review.php`: new slim reader `import_job_case_rows(PDO $pdo, int $jobId): array`. It returns
   `[key, name as written, unit, price, category or sheet name, existing item id|null]` for exactly the rows `import_case_evidence_pass()`
   looks at (sheet triage = import, `unit_price > 0`), with the key from `import_case_row_key()`. The endpoint uses only this and
   never walks `analysis_json` itself.
1. `includes/import/review.php`: new `import_job_set_ai_case()` (writes `_ai_case` under the same status rules as
   `import_job_set_update_fields`; refuses committed/cancelled jobs).
2. `includes/import/resolve.php`: the D9 change in `import_case_evidence_pass()`. A guarded `require_once` of the engine
   (`is_file`), using only the pure functions `rh_ai_sk_row_case`, `rh_ai_sk_combine` and `rh_ai_sk_apply_on`.
3. `includes/import/review.php` `import_job_answer()`: after a case-price answer is saved, `rh_ai_sk_learn_import(...)` (guarded).
4. `admin/data-import.php`: the mount on the review screen. "Check names for crate prices" posts `action=check&surface=import&job=ID`.
   The server reads the job's rows through `import_job_case_rows()` (names, unit, price, category or sheet name; never notes). A chip "Use crate of N"
   answers through the **existing** answer endpoint (same CSRF, same `import_answer_allowed` validation).
4b. (Fable 2026-11-17) While the helper is ON and some names in the job were not checked (never run, cut short, or a new file row), the Confirm
   step says so in one line before Confirm: "12 item names were not checked for crate prices. Check names first, or confirm without it."
   It never blocks Confirm.
5. `includes/ai/ai-import.php` (mine): the `import_job` tool explains `case_evidence.ai` in plain words.

## 5. Menu Management (c): the hook contract
1. `admin/menu-management.php`: mount + the "Check stock links" button (posts `action=check&surface=menu`). The server reads
   links and prices itself.
2. The item modal: when the cached result for a link differs from its amount, one line with a "Use this" link that sets the
   amount field. Save is unchanged.

---

## 6. File-by-file build plan (who builds what)
Owner rule: only the AI lead edits AI files, and the AI lead's edits outside AI files are limited to the listed exceptions.
So **the surface hooks are built by the owning leads**, to the contracts in §3-§5, and **I review each touch point**
against the rails above. I do not edit their files.

| # | file | owner | what |
|---|---|---|---|
| 1 | `admin/migrations/0NN_ai_stock_kinds.php` (next free number at build) | AI lead | `ai_stock_kinds` table + D14 settings seed. Idempotent, Liwonde first |
| 2 | `includes/ai/ai-stock-kinds.php` | AI lead | D3-D13 engine (pure parts: `rh_ai_sk_parse`, `_agree`, `_combine`, `_row_case`, `_apply_on`, `_wording`, `_menu_amount`) |
| 3 | `admin/api/ai-stock-helper.php` | AI lead | actions `check`, `price_of_one`, `status` (session + CSRF + gate) |
| 4 | `admin/js/ai-stock-helper.js` | AI lead | mount, button, progress, chips, "Use all agreed suggestions", painting by data attributes; `node --check` |
| 5 | `includes/ai/ai-core.php` | AI lead | D14 defaults |
| 6 | `admin/ai-settings.php` | AI lead | switch + settings with helper lines + "Crate-price name test" upload/report |
| 7 | `includes/ai/ai-evals.php` | AI lead | name test runner, recording store, nightly replay |
| 8 | `includes/ai/ai-import.php` | AI lead | explain `case_evidence.ai` |
| 9 | `scripts/tests/ai_assistant_test.php` | AI lead | every `sk_*` test above |
| 10 | `admin/stock-cost-fix.php` + `admin/includes/stock-cost-fix-lib.php` | **dept-stock-lead** (after the ui-lead restyle ships) | §3 hook; tests in `stock_cost_fix_test.php` (`src` passthrough, no maths change, off = identical) |
| 11 | `includes/import/resolve.php`, `includes/import/review.php`, `admin/data-import.php` | **dept-import-lead** | §4 hook; tests in the import suite (truth table through resolve, snapshot determinism, off = identical) |
| 12 | `admin/menu-management.php` | **dept-restaurant-lead** | §5 hook; its own test file |
Ship order (each its own ship, ship gate in DEPARTMENTS.md): **1-9** (helper OFF, nothing visible) → **10** (Fix crate prices) →
**11** (Import) → **12** (Menu). Accounting lead reviews #10 (costs). One qa-auditor review per ship.
`.gitignore` is not touched (nothing is written to files).

### §6b. Owner fault folded in (2026-11-17): the Assistant notice card covered page buttons
The `.rh-ai-notice-card` (`admin/includes/ai-panel.php` + `admin/css/ai-panel.css`, fixed bottom-right, z 9997) covered Preview on
Fix crate prices. One shared pattern: the card shows for a few seconds, then **collapses into a small pill** ("1 notice") docked beside
the Assistant button. The pill is small and takes no clicks outside itself, so it cannot cover page actions; tapping it opens the card again.
Hovering over or focusing the card holds it open. The same applies to every notice on every page.

## 7. Minimal live test plan (the owner, in the app, at Liwonde only; shells never spend)
1. AI Settings (Liwonde): turn **Stock name check** on. Check that Fast and Fallback are ticked.
2. Fill the CSV (N for crate items, Y for per-bottle items), then AI Settings → **Crate-price name test** → upload. Expect recall ≥ 92% of N rows,
   0 Y rows with an agreed chip. Cost shown about US$0.05.
3. Stock → **Fix crate prices** → "Check names for crate prices". Beers, ciders, minerals, energy drinks and mixers are flagged;
   whiskies and wines are not. Press "Use all agreed suggestions" → Preview shows the "From suggestions" group → Confirm at Liwonde.
   Reload: no new call (Assistant usage unchanged).
4. **Import** a 5-row sheet (3 crate-priced beers, 2 spirits): 3 questions with "Use crate of N", spirits untouched. Turn the
   switch off and reload: exactly today's questions.
5. **Menu Management** → "Check stock links" on a whisky tot item: suggests 0.0333 bottle (25 ml of 750 ml). Use this → Save.
6. Assistant usage page: all calls recorded; total under US$0.10.
Rosalyn's: stays OFF until the owner switches it on after Liwonde passes.

## 8. Guides / Assistant sync entries (draft; added to `.claude/GUIDES_AI_SYNC.md` "Pending" at each ship, not before)
- Guides (both hotels, once the matching hook ships): Fix crate prices: "Check names for crate prices", chips, "Use all agreed
  suggestions", the "From suggestions" group in Preview, "This is the price of one". Import: crate questions raised from item
  names with "Use crate of N". Menu Management: "Check stock links" and "Use this". AI Settings: the Stock name check switch
  and the name test. Each says it only appears when the Assistant is on, and that nothing changes until Preview/Confirm or Save.
- Assistant: the `import_job` tool explains `case_evidence.ai`. The chat knowledge answers "how do I fix crate prices" with the
  button and its limits. The usage page groups calls as "Stock name check".

## 9. Open questions for the owner (max 3, with recommendations; built as recommended unless the owner says otherwise)
- **Q1. Two models must agree before a size is offered for "Use all agreed suggestions"?** This doubles the cost of a list run:
  about US$0.05 instead of US$0.02 for all 171 items, once; repeat visits are free. **Recommend: yes** (`ai_stock_two_models` = 1),
  kept separate from the chat swarm, which stays off.
- **Q2. "Stock cost per sale is above the selling price" warning in Menu Management, with the Assistant off too?** It is plain
  arithmetic and would have pointed at these crate prices. **Recommend: yes**, as a small non-AI patch by the restaurant lead
  (PATCH_QUEUE), not gated behind the Assistant.
- **Q3. Bar measures for the Menu suggestion: one tot = 25 ml, one glass of wine = 175 ml, an unmarked bottle = 750 ml?**
  **Recommend: yes**, seeded at both hotels and changeable in AI Settings (no hard-coded hotel facts).

`ASSUMPTION:` (2026-11-17; the owner delegated AI decisions, so these are settled with the recommended answers) Q1: two models must agree.
Q2: the cost-above-selling-price warning in Menu Management works with the Assistant OFF (restaurant lead, non-AI, Phase 3).
Q3: measures of 25 ml (tot), 175 ml (glass) and 750 ml (unmarked bottle), seeded and editable in AI Settings.

`ASSUMPTION:` (logged here, the owner can override) only admin/manager answers teach the hotel-wide cache; learning runs only
while the helper is ON; the helper ships OFF at both hotels.
`IDEA:` the same name check behind Stock Items' existing "May be a case price" note.
