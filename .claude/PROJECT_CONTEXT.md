# PROJECT_CONTEXT — Rosalyn's Hotel 2026

> Required reading for build-planner before any BUILD_PLAN.md phase.
> Every objective in BUILD_PLAN.md must state which gap below it closes.

## What this project is

A complete hotel website **and** property-management system (PMS) for Rosalyn's Hotel,
built by ProManaged IT. Single vanilla-PHP codebase, two faces:

1. **Public site** (root `*.php`) — marketing pages, direct room booking engine
   (`booking.php`), conference/events enquiries, gym memberships & schedule,
   restaurant menus, reviews, contact. PWA-enabled (`sw.js`, `manifest.php`, `offline.php`).
2. **Back office** (`admin/`) — full PMS: bookings/calendar/housekeeping, POS + KDS
   (kitchen display), stock & inventory (suppliers, recipes, wastage, barcode receiving),
   gym management (members, packages, check-in, classes), events & conference management,
   finance (invoices, receipts, credit notes, payments, refunds, end-of-day,
   shift close, accounting dashboard), reports, user management with per-page permissions,
   content management (pages, gallery, media, footers, deals), integrations
   (WhatsApp, Facebook), backups, system logs, visitor analytics.
3. **JSON API** (`api/`) — key-authenticated, permission-scoped endpoints
   (rooms, bookings, reviews, POS, reports) accessed via a central router.

## Stack & conventions (verified by scan)

- PHP ≥ 7.4, no framework. One page = one file. Shared logic in `includes/*.php`
  (plain functions, no classes/namespaces in app code). Composer only for
  PHPMailer + TCPDF; PSR-4 `HotelWebsite\ → src/` declared but app code is procedural.
- DB: MySQL via PDO, `config/database.php` (creds from `.env` through
  `config/database.local.php`). Prepared statements everywhere. `BALANCE_TOLERANCE`
  constant for money comparisons — never compare raw floats.
- Auth: `admin/admin-init.php` = session auth + CSRF (`generateCsrfToken()`) +
  security headers + per-page permission checks (`admin/includes/permissions.php`).
  Public forms use `includes/public-csrf.php`. Input via `includes/validation.php`
  (`sanitizeString`, `validateEmail`, …).
- Front-end: plain HTML/CSS/JS, no build step. CSS per-module in `css/`, admin CSS in
  `admin/css/`. Migrations in `admin/migrations/`. Caching in `config/cache.php` /
  `config/page-cache.php`.
- Tests: PHPUnit declared in `require-dev` but **no test suite found** — verify in Phase 0.

## Who uses it & core problem

- **Guests** — find the hotel, see rooms/menus/gym, book and pay without phoning.
- **Front-desk & ops staff** — manage reservations, check-ins, housekeeping, POS orders,
  kitchen tickets, stock.
- **Management/owners** — finance reports, EOD, accounting, analytics.
The core problem: replace manual/fragmented hotel operations with one integrated,
self-hosted system with **no per-booking commission** (vs OTAs) and no SaaS fees.

## Best-in-class bar

Real products that solve this problem well:

1. **Cloudbeds** — PMS + booking engine. Does that this codebase doesn't yet:
   integrated online payment capture at booking time; channel manager (OTA sync);
   automated guest-communication lifecycle (pre-arrival, in-stay, post-stay emails).
2. **Mews** — modern PMS UX. Does better: fast task-oriented staff workflows that work
   on tablets/phones at the desk; heavy automation of routine state changes;
   open API-first design with webhooks.
3. **Little Hotelier (SiteMinder)** — small-property focus. Does better: dead-simple
   direct-booking widget with conversion-optimized 3-step checkout; rate plans &
   promotions surfaced in the booking flow; mobile app for owners.

## Gaps vs that bar — ranked by impact

1. **No safety net.** No visible automated tests despite PHPUnit being declared; several
   monolithic pages (`booking.php` ≈ 197 KB, `gym.php` ≈ 54 KB) mix logic + markup, making
   every change risky. Best-in-class systems ship changes daily because regressions are caught.
   → Highest-impact fix: smoke-test harness for booking, payment, and POS money paths.
2. **Payment capture at booking time** — verify in Phase 0 whether the public booking flow
   takes online payment or only records tentative bookings for manual settlement. If manual,
   this is the single biggest conversion/revenue gap vs Cloudbeds/SiteMinder.
3. **Guest communication lifecycle** — confirmation emails exist; automated pre-arrival /
   post-stay (review request) sequences appear absent. Cheap, high-retention win.
4. **Performance & page weight** — 197 KB of PHP per booking page render; audit output size,
   image proxying, and cache hit rates. Direct-booking conversion is latency-sensitive.
5. **Staff UX on mobile/tablet** — admin pages are numerous; verify responsive behaviour of
   the highest-frequency workflows (POS, KDS, check-in, housekeeping) matches the Mews bar.
6. **Accessibility & polish** on public pages — booking widget keyboard/screen-reader flow,
   contrast, touch targets.
7. **OTA/channel sync** — deliberate scope decision, not a defect. Parked: needs owner input
   before any work (subscription costs, rate parity). Do not build without a decision.

## Out of scope unless the owner asks

Framework rewrites, OTA integration (see gap 7), multi-property support, replacing the
procedural style with OOP. The bar is "best small-hotel direct-booking + PMS", not "rebuild Mews".
