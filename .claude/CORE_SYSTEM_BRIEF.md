# CORE SYSTEM BRIEF — Rosalyn's Hotel 2026

> **Every agent reads this file first, before doing anything else.** It is deliberately short.
> It exists so no agent ever has to "explore" to learn what this system is.
> Detail lives in `.claude/SYSTEM_MAP.md` (file/table map) — never re-scan the repo for it.

## What the system is

One vanilla-PHP (≥7.4, no framework, no build step) codebase that is simultaneously a
**hotel website** and a **property-management system (PMS)** for a single property,
self-hosted, with **no OTA commission and no SaaS fees**. Three faces:

| Face | Location | Purpose |
|------|----------|---------|
| Public site | root `*.php` | marketing, direct booking engine, enquiries, PWA |
| Back office | `admin/` | the whole PMS — ops, money, content, users |
| JSON API | `api/` | key-auth, permission-scoped, router-fronted |

## Core functional domains (the whole system, in one table)

Every agent must know these exist and roughly where they live. If your task touches a
domain, read that domain's section of `SYSTEM_MAP.md` — not the whole repo.

| # | Domain | Core functionality | Primary files |
|---|--------|--------------------|---------------|
| 1 | **Booking engine** | availability search, rate plans/discounts, capacity-gated occupancy, auto-split of large parties, per-room `FOR UPDATE` locks on every create/mutate path, confirmation | `booking.php`, `includes/booking-widget.php`, `admin/create-booking.php`, `admin/edit-booking.php`, `admin/bookings.php` |
| 2 | **Reservations & front desk** | calendar, check-in/check-out, room assignment, extend stay, no-shows, multi-room folios, housekeeping status | `admin/bookings.php`, `admin/check-in*.php`, `admin/housekeeping*.php`, `admin/individual-rooms.php` |
| 3 | **Rooms & inventory of rooms** | rooms ARE the room types (no `room_types` table; `bookings.room_id → rooms.id`), joined rooms via `room_combinations` | `admin/room-management.php`, `admin/individual-rooms.php` |
| 4 | **POS & F&B** | orders, bar tabs (add-to-tab, repeat round, covers), KDS kitchen display, auto-serve, menus | `admin/pos*.php`, `admin/kds*.php`, `admin/menu*.php` |
| 5 | **Stock & procurement** | FIFO stock engine, recipes, wastage/shrinkage, barcode receiving, suppliers, purchase orders, reorder/par levels | `admin/stock*.php`, `admin/procurement*.php`, `admin/suppliers*.php` |
| 6 | **Finance & accounting** | invoices, receipts, credit notes, payments, refunds, end-of-day, shift close, accounting dashboard, VAT (`vat_pricing_mode`: off/inclusive/exclusive; `payment_amount` is always **net**; F&B prices always gross vs mode-driven room prices) | `admin/invoices.php`, `admin/payments*.php`, `admin/end-of-day.php`, `admin/accounting*.php`, `includes/`+`config/database.php` helpers |
| 7 | **Gym** | package-driven membership enrol (auto fee + expiry), complimentary hotel-guest option, check-in, classes, optional slot calendar (`gym_hours`, `gym_slot_reservations`) | `gym.php`, `gym-schedule.php`, `admin/gym*.php` |
| 8 | **Conference & events** | `conference_inquiries` is the live model (`conference_bookings` is legacy), double-booking guard, payment snapshot sync, events media | `conference.php`, `events.php`, `admin/conference*.php`, `admin/events*.php` |
| 9 | **Guest communication** | confirmation + pre-arrival + post-stay review-request emails, template-driven, toggleable in admin, PHPMailer via `config/email.php` | `includes/email*.php`, `admin/email-templates.php`, `scripts/` cron senders |
| 10 | **Content & marketing** | pages, gallery, media, footers, deals, reviews, SEO meta, visitor analytics | `admin/content*.php`, `admin/gallery*.php`, `includes/seo-meta.php`, `submit-review.php` |
| 11 | **Admin platform** | session auth, CSRF, security headers, per-page permissions, users/roles, system logs, backups, integrations (WhatsApp/Facebook), dashboard presets/module gating | `admin/admin-init.php`, `admin/includes/permissions.php`, `admin/user*.php`, `admin/module-presets.php` |
| 12 | **API** | key-auth JSON endpoints for rooms, bookings, reviews, POS, reports behind a router | `api/` |
| 13 | **Platform/PWA/perf** | `sw.js`, `public-sw.js`, `manifest.php`, `offline.php`, `config/cache.php`, `config/page-cache.php` | as listed |
| 14 | **Safety net** | smoke tests (`scripts/smoke_test_booking.php`, `scripts/smoke_test_finance.php`), migrations in `admin/migrations/` | as listed |

## Users and what they need

- **Guests** — find the hotel, see rooms/menus/gym, book on a phone without phoning.
- **Front-desk / ops staff** — reservations, check-ins, housekeeping, POS, KDS, stock;
  used **standing up on a tablet**, so 768–1024px and 44×44px touch targets are functional
  requirements, not polish.
- **Management/owners** — finance, EOD, accounting, analytics, laptop-width data tables.

## The bar (what "good" means here)

Cloudbeds (booking engine + PMS depth) · Mews (fast tablet-first staff workflows,
automation of routine state changes) · Little Hotelier (dead-simple conversion-optimized
direct booking). Not a rewrite of any of them — "best small-hotel direct booking + PMS".

## Non-negotiable code conventions (all agents, all domains)

- Prepared statements always — never interpolate a variable into SQL.
- `htmlspecialchars()` / `sanitizeString()` on every echo of user data.
- CSRF on every POST — admin: `admin-init.php` `$csrf_token`; public: `includes/public-csrf.php`.
- Admin pages: `require_once __DIR__ . '/admin-init.php';` before ANY output.
- API endpoints: `API_ACCESS_ALLOWED` guard + `$auth->checkPermission()` + `ApiResponse::`.
- Money: compare via `BALANCE_TOLERANCE`, never raw float equality. Refunds net out of paid.
- Reuse `includes/` helpers before writing new ones.
- Procedural PHP. No frameworks, no Composer additions, no build steps, no CDN deps.
- `php -l` every changed PHP file. `node --check` JS when available.
- Never read `vendor/`, `PHPMailer/`, `node_modules/`, `.git/`, `logs/`, `cache/`,
  `backups/`, `images/`, `Database/`.

## Hard safety rails (all agents, no exceptions)

Never commit or push. Never `DROP`/`TRUNCATE`/`DELETE`-without-`WHERE`. Never edit `.env`
or `config/*local*`. Never print credentials. Never delete files. Never create README or
documentation files unless the brief explicitly says so.

## Escalation rule — what must be ASKED vs what must be ASSUMED

Autonomy is the default, but it is **not** a licence to redesign the system or change how it
behaves in ways the owner did not sanction. Classify every decision before acting:

**ESCALATE — stop and ask the owner precisely (never assume, never "improve" quietly):**

1. **Money semantics** — how a price, VAT, discount, deposit, refund, balance, commission or
   payout is calculated; changing `vat_pricing_mode` behaviour, net-vs-gross treatment, or
   what counts toward `amount_due`.
2. **Booking/availability rules** — overbooking tolerance, cancellation/no-show policy,
   minimum stay, capacity or occupancy limits, what blocks a room, lock/allocation strategy.
3. **Auth, permissions, or security posture** — who can see or do what, adding/removing a
   permission gate, session/cookie policy, API key handling, rate limits, relaxing any rail.
4. **Schema changes that are not purely additive** — dropping/renaming a column or table,
   changing a type, backfilling or rewriting existing rows, anything not reversible.
5. **Guest-visible flow changes** — adding, removing or reordering a step or required field
   in the public booking flow; changing what a guest is charged, shown, or agreed to.
6. **Anything that sends real messages** — enabling an email/WhatsApp/SMS sequence against
   live guest data, or changing an existing template's meaning (not its typos).
7. **Brand/design system changes** — palette, typography, spacing scale, or the layout
   system of a page. Applying existing tokens is polish; inventing new ones is a design change.
8. **Deleting or renaming files, or removing an existing feature/endpoint**, even if it
   looks dead.
9. **Anything with a cost or an external dependency** — new package, CDN, third-party
   service, subscription, OTA/channel integration.

**ASSUME — decide it yourself and log `ASSUMPTION: <one line>`:** naming, code placement
within an agreed file, which existing helper to reuse, copy wording, ordering of non-required
UI elements, applying existing design tokens, test data, log verbosity, additive-only columns
on a new table the brief already sanctioned, and any reversible internal implementation detail.

**How to escalate (precision is the point):** never ask an open question. State it as
`BLOCKED: <domain #> — <the exact decision>` with (a) what triggered it, (b) 2–3 concrete
options with their consequence in one line each, (c) your recommendation. Subagents never
ask the owner directly — they report the `BLOCKED:` line and continue with other work; only
the orchestrator surfaces it, batched, using AskUserQuestion.

## Output discipline (all agents, no exceptions)

The owner does **not** want code in the terminal. Report back:
**files changed (paths only) · ≤4-line outcome · lint result · blockers.**
No code blocks, no diffs, no before/after snippets, no narration of what you are about to do.
