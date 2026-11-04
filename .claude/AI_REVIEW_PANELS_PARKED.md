# PARKED (owner 2026-11-04: "put a pin on it before building") - in-page AI Review panels

Status: PARKED. Do not build until the owner says so. Owner: dept-ai-lead builds; the owning lead checks the figures
(stock-lead / accounting-lead / rooms-lead). Money panel also reviewed by accounting lead. Ship gate = AI scope rails.

## Idea
One "Review" panel at the top of the Stock, Accounting and Bookings dashboards. A professional review a person could not
produce by hand across thousands of rows. Fixed SQL checks do the finding (zero model tokens); the model only ranks and
words the top findings. Every finding links to the screen that fixes it. Read-only: nothing changes by itself.

## What exists today (do not rebuild)
Global Assistant chat (admin/includes/ai-panel.php), read tools in includes/ai/ai-insights.php (inventory_watch, money_leaks,
room_performance, booking_pace, balances_due), deterministic briefing finders rh_ai_briefing_findings(). The new panels reuse
these finders; add new checks beside them.

## Checks per panel (first set in bold)
STOCK: **recipe cost vs menu price (thin/negative margin)**; **run-out forecast from real POS usage**; **count variance
patterns (same item short repeatedly, by shift/day)**; supplier price creep across POs; dead stock and expiring-unsold;
reorder quantity suggestions; wastage vs sales (waste or loss); menu items that deduct no stock.
ACCOUNTING: **aged receivables with who-to-chase order**; **refunds/voids/overrides outliers by staff and day**; **day
close variances (till vs expected, repeat offenders)**; VAT and levy sanity (rate mismatches, zero-tax lines); revenue mix
and month-on-month movers; unpaid deposits on upcoming stays; cash-flow outlook (due in vs due out). Money wording follows
accounting-decisions-2026-10 (refunds shown in full).
BOOKINGS: **pace vs same period last year and pickup gaps**; **cancellation and no-show patterns by source/room type/lead
time**; ADR and occupancy by room type with underpriced/sold-out dates; orphan nights (1-2 night gaps) and joined-room
conflicts; channel mix and lead time; arrivals needing action (unpaid, no room assigned, special requests).

## Rules (existing AI rails apply)
No "none/0" without a checked total; contradiction forces unfiltered recheck; permission per panel (stock_*, accounting/
reports, bookings); no personal data to the model (vault tokens); preview-then-confirm for any action; no sparkle/wand/
robot icons or "smart" labels; every figure card/column has a plain helper line; no hard-coded hotel facts; no MRA wording.

## AI OFF = no impact (verified design, confirm in build)
The Assistant panel already prints nothing unless rh_ai_enabled() and ai_assistant_use (admin/includes/ai-panel.php).
Review panels use the same gate and are rendered server-side only when on: when off, no markup, no CSS/JS link, no
request, no DB read, no layout gap. Page must be byte-identical to today with AI off. Test: render each dashboard with AI
off and diff against baseline; plus a permission-off and module-off case. Cache findings per day so page load never waits.

## Cost
Findings cached (daily, plus on-demand refresh); one cheap-model call per panel per refresh to word the top 5-8 findings.
Within the existing monthly AI budget and kill switches.

## Open decisions for the owner
1. First set only (bold items) or all checks?  2. Panel on the dashboard only, or a small badge on the other stock/accounting/
booking pages?  3. Refresh: daily + button, or button only?

## Owner decisions 2026-11-04
- Tiers confirmed: T1 runs itself (draft/remind/queue/flag, logged, undoable); T2 one-tap approve with exact preview; T3 never
  autonomous (payments, refunds, voids, price/rate changes, deletes, accounting edits).
- Scope: build EVERYTHING, "the future of PMS/HMS"; goal is a stakeholder-demo wow effect. Plan expanded beyond the first set:
  Ask-why drill-down, what-if simulator, forecasts with ranges, cross-department findings, owner morning brief, board pack
  export, watch rules in plain words, finding history + proof. Honest limit: forecasts and patterns need real history; demo on
  Liwonde (test hotel) data, never invented figures.

## Owner decisions 2026-11-04 (later)
- PARKED further: reviews for restaurant, events, gym and guest reviews (wait); the stakeholder presentation (soon, not now).
- DESIGN DIRECTION: decision-first, not detail-first. Reviews exist to change what a decision maker does next, so they surface
  the few high-impact decisions that are live NOW, not small facts. Dynamic, not a fixed question-and-answer list: what is
  shown, asked and followed up depends on the data and on the answers given. See "Decision cards" below.

## Decision cards (replaces the check list as the primary view)
Each card: the decision, why it is live now, money/risk at stake, evidence (traceable rows), 2-3 options with projected
outcome ranges (best/expected/worst + confidence + history used), a recommendation, and the action tier it unlocks.
Selection: score = impact (money at stake as a share of monthly revenue or cash) x time-sensitivity x confidence; anything
under the impact threshold is hidden (still reachable under "More"). The set changes every refresh; resolved or stale cards
drop out. Follow-ups are generated from the answer ("What if we hold rates?" -> re-forecast), never from a fixed script.
Decisions per area (starting set): BOOKINGS - rate and inventory strategy next 90 days, demand/channel mix, cancellation and
deposit exposure, which room types earn vs drag. ACCOUNTING - cash outlook and runway, collections and credit exposure, profit
by department, control/leakage risk, tax liability. STOCK - what to commit to buying and cash tied up, menu/price profitability,
waste/loss exposure, supplier dependence and switch options. Small details live in the drill-down, not on the card.

## HARD RULES (owner 2026-11-05) - apply to everything in this feature
1. INVISIBLE AI. No AI-looking colours, icons or wording anywhere around it - no sparkle/wand/robot/brain/chip icons, no
   purple/neon/gradient "AI" styling, no "AI", "smart", "insight", "assistant", "generated", "powered by" labels, no "thinking..."
   states. It uses the normal admin tokens, normal cards and plain words ("Decisions", "Today's brief"). Staff should feel the
   system simply knows what matters, as if someone in the background is delivering it. Same rule for emails, PDFs and the board pack.
2. DAILY BRIEF ACROSS DEPARTMENTS. One brief per person per day, shaped by role and permissions, joining rooms, restaurant/stock,
   accounting (and later events/gym/guest voice): what changed, which decisions are live, what ran itself (tier 1), what waits
   for approval (tier 2). Built from the same decision cards, so there is one source of truth. Extends rh_ai_briefing_findings().
3. TIMELESS AND CHEAPEST. Model-agnostic: all model access goes through the provider/router layer (includes/ai/ai-providers.php,
   ai-router.php); no model name, price, token limit or vendor feature hard-coded in the feature; the model catalog stays data.
   Works as long as there is a key and any capable model: swap or upgrade with a settings change, no code change. Deterministic
   SQL does the finding (zero tokens); the model only ranks/words the top few; cache everything; skip the call when nothing
   changed; cheapest model that passes the evals; hard monthly cap and kill switch. If no model is reachable, the cards still
   show with plain templated wording (degrades, never breaks). No dependency on a specific vendor SDK, no build step.
