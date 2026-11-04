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
