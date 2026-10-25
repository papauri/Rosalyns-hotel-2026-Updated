# Patch queue — owner-approved feature patches and security patches

Owned by `dept-patch-lead`. Owner approved this whole batch on 2026-10-22 ("Approve the monthly
role outs"), so these items are exempt from the feature freeze. Anything NOT on this list still
needs owner approval first (add it under "Proposed" with one line and ask).

Status: TODO · DOING · REVIEW · SHIPPED · BLOCKED (owner). One commit per item or per small group.
Owner lead = the department lead whose files it is; the patch lead plans, briefs, gates and ships.

## PRIORITY (owner 2026-10-22)
- TODO (owner-approved FEATURE 2026-10-26) · AI Assistant in the admin portal — spec .claude/AI_ASSISTANT_PLAN.md (phases 0-5, both hotels, off by default). Executor Claude Opus 5.5; reviews rooms lead, accounting lead (cancel/checkout/figures), patch lead (security), ui-lead, qa-auditor; guides-kb-lead after each ship.

- SHIPPED dae3e40 / Liwonde b0fb15b (2026-10-22) · Group / multi-room booking form (admin/create-booking.php): identical rooms priced differently (1,610,000 vs 805,000), availability not reducing as rooms get a type, rooms needed vs guests vs available unclear, step button arrow should point down. · rooms lead (accounting review after)

- SHIPPED phase 1 88b91db + test fix 51fce31 / Liwonde 11896d1 + a304f8f; PHASE 2 "Also booking right now" banner on New Booking 74bf823 / Liwonde 0373db0; migration 064 confirmed applied on Liwonde 2026-10-23 (migrate.php --status, 0 pending); guides 35055a6 / ca3ee40. The dashboard card was not built (not asked again). Original note: (phase 1 built 2026-10-22: migration 064 applied on Rosalyn's, heartbeat, Who's online panel on User Management + System Logs, scripts/tests/admin_presence.php; Liwonde needs migrate.php --run after port; PHASE 2 "someone else is booking" banner + dashboard card still TODO, data ready: admin_presence.current_page) · Staff presence: 'Who's online' for admins/managers (name, role, current screen, last active; Active <5 min, Away 5-15, gone after; sign-out clears) on the audit/User Management area + dashboard card; 'Someone else is booking' banner for booking-permission users with dates/room types once entered and an overlap warning on Create Booking. Needs one small table via admin/migrations (owner request = approval). Reuse the existing notification polling. · patch lead + rooms lead

- SHIPPED 1c39034 + public booking page 742469f / Liwonde 9f76d27 + 6829a6b (2026-10-23); guides 1c5b458 / 92df08f. OPEN CHECK for the accounting lead: accounting review of child pricing is not recorded - confirm child charges with VAT/levy in quotes, invoices, edit-booking and the public page. (approved feature 2026-10-22) · Child pricing per room type AND per individual room (room overrides type): charge mode % of room rate OR fixed amount per child per night; "free children" allowance (first N free, e.g. 2); maximum children per room. Used identically by create-booking, public booking, edit-booking, quotations, invoices. Migration in admin/migrations (owner approved); accounting review. · rooms lead + accounting lead
- SHIPPED b9dadac / Liwonde f125ae7 (+ 497ac51 / 2e768da); later guides 1c5b458 / 92df08f, 35055a6 / ca3ee40, be08571 / 7026835 (owner 2026-10-22) · Guides + KB: update docs/guides for everything shipped 2026-10-21/22 not yet covered (security: staff document download page, API keys shown once + X-API-Key header, receptionist payments/receipts defaults, room pictures need Media edit; rooms: one clean-room rule, room change shows only current room, in-house same-type moves; WhatsApp private links; Bookings page conference block; Website CMS: gallery drag-to-reorder, Media Portal Inactive filter, hidden section headers, adding code-only headers, safe footer links; group booking screen; child pricing; presence) and check the KB search finds them. · guides-kb-lead

- SHIPPED c2b86e3 / Liwonde 2879958; guides be08571 / 7026835 (owner 2026-10-23) · Sign in with username OR email (admin/login.php); Staff & Access shows usernames and refuses a duplicate email on add/edit (admin/user-management.php); scripts/tests/login_email.php (25 checks, in run_tests; page_harness gained cfg.anon). Awaiting ship + port + guides. · patch lead

## Security patches (standing — always allowed, highest priority)
- SHIPPED 37f073f / Liwonde 3a72ba6 (+ web rules a6c9428 / b8f0ea4, API keys hashed 73ee71e / 5f6861d; 2026-10-22). Owner-side follow-ups are listed as BLOCKED (owner) lines below. SWEEP 2026-10-22 findings F1-F13 fixed in Rosalyn's 2026-10-22, awaiting QA + ship + port (tests 3/3 OK, browser 1400/390 clean, download endpoint checked in page_harness). Owner follow-ups: WhatsApp 'Send invoice' link (admin/booking-details.php) now points at a denied static URL - needs a signed-link decision; delete/rotate error_log files on the live hosts; confirm APP_ENCRYPTION_SALT in each host .env; existing keys keep GET access, writes to housekeeping/maintenance-schedules/room-photos/room-amenities now need the new rooms.manage scope. Original findings:
  F1 high: invoice/receipt/credit-note/quotation PDFs in web-served folders with no deny rule; admin links point at the static file; guessable names → deny-all .htaccess on invoices/ (+subfolders) and quotations/, one permission-checked download endpoint, repoint admin links, check email links/attachments.
  F2 high: api/housekeeping.php, api/maintenance-schedules.php, api/room-photos.php, api/room-amenities.php writes without checkPermission → any valid API key can write; add a write scope per method, show it in admin/api-keys.php.
  F3 high: extensionless error_log files in admin/, api/, admin/api/ are web-served (.htaccess denies *.log only) → add error_log to the deny rule (root + subfolders); owner deletes/rotates them on the live hosts.
  F4 medium: admin/api/menu-recipe.php writes and admin/api/room-pictures.php POST/PUT/DELETE have no CSRF check → validateCsrfToken on non-GET + callers' JS.
  F5 medium: forgot-password builds the reset link from the Host header when site_url is empty → never use HTTP_HOST.
  F6 medium: gym-schedule.php reservation has no per-IP limit → pub_ip_rate_limit.
  F7 medium: api/cookie-consent.php unauthenticated, no limit, trusts X-Forwarded-For → limit, REMOTE_ADDR, one row per IP per day.
  F8 low-med: raw exception text in API JSON (api/index.php, availability, bookings, admin/api/room-pictures, review-stats) → generic message, log the detail.
  F9 low-med: invalid API keys never throttled (bcrypt over all keys per attempt); key accepted in ?api_key= → per-IP failed-auth limit, header only.
  F10 low: API-key encryption falls back to a literal default salt → refuse without APP_ENCRYPTION_SALT (owner checks .env on each host).
  F11 low: session cookie without Secure on HTTPS → set dynamically.
  F12 low: login reveals real usernames via "attempts remaining"; strangers can lock accounts → same message for all failures.
  F13 low: docs/ contract/equipment PDFs public; POS APIs (kds-action, void-order, cancel-order, delete-order, pos-notifications, reports-export) lack rhApiModuleGate → admin session for docs, add the module gate.
- TODO · Standing security sweep, both hotels (shared code): auth/session, CSRF on every POST,
  SQL injection, output escaping/XSS, file uploads, path traversal, API key/rate limits, public
  forms, admin API module/permission gates, secrets in repo. Findings → fix → re-sweep monthly.
- TODO · Payments-page refund that is still pending closes kitchen lines irreversibly; close lines
  only when the covering refund is completed (finance-account-sync.php). · accounting lead

- TODO · Security follow-ups from the F1-F13 QA: facebook/WhatsApp/review-source helpers still use the literal default salt (includes/facebook-functions.php:47) → use APP_ENCRYPTION_SALT; document-download type=invoice accepts 'bookings' for any payments.id → narrow per account type; order-lifecycle (stock module) calls void/cancel APIs now gated on pos. · patch lead
- SHIPPED b4fd83c / Liwonde 23c01d5 (2026-10-22) · WhatsApp "send invoice" now sends a private 30-day signed link (includes/doc-links.php rh_doc_signed_url, public invoice-view.php, HMAC on type+booking id+expiry, APP_ENCRYPTION_SALT or a site_settings secret, per-IP limit, friendly expired page; used by admin/booking-details.php and sendBookingInvoiceWhatsApp, only when WhatsApp is on). Test scripts/tests/doc_link.php (26 checks, in run_tests). Awaiting QA + ship + port. · patch lead

- SHIPPED b4fd83c / Liwonde 23c01d5 (owner: yes) · Private invoice links: a CANCELLED booking's link stops working (today it serves the invoice until the 30 days run out; deleted bookings already 410)? Also the per-IP limit (30/10 min) counts valid views too. · patch lead

- SHIPPED c31d166 + bb8ef23 / Liwonde 1709edf + e86fc6e (2026-10-22); guides 1c5b458 / 92df08f; follow-up (b) done in config/database.php, (a) is the TODO below (owner 2026-10-22: "check cache management logic and clear the main error_log files from here") · Cache audit fixes in config/cache.php + admin/cache-management.php + root .htaccess: cache/*.cache (can hold decrypted SMTP settings) was web-readable → deny rule + self-written cache/.htaccess; per-type switches (Email/Rooms/Tables/Content) did nothing → key-based type; settings clear left the in-memory copy; switch read cost 2 queries per cache read → once per request; clear counts now real; "Last cleared" shown; ?msg= banner removed; schedule form JS runs after in-app navigation. NEW "Error logs" panel (admin role only, fixed whitelist, truncate to 0, audit-logged; admin/includes/error-log-manager.php). Tests scripts/tests/cache_logic.php (33) + error_log_clear.php (52) in run_tests. Awaiting QA + ship + port. Owner/lead follow-ups: (a) includes/image-proxy.php ignores the Image Cache switch (always caches 7 days); (b) config/database.php:~1490 setCache("email_setting_…") should skip is_encrypted values so credentials never reach a file; (c) after deploy, the live hosts get cache/.htaccess on first cache write. · patch lead
- REVIEW (2026-10-23, owner approved; reworked after QA) · includes/image-proxy.php honours the Image Cache switch (and the global cache switch) WITHOUT starting the site: it loads only config/cache.php and checks the flag file data/image-cache-off.flag (present = off; missing = on, fail open). The flag is written/removed by rhSyncImageCacheFlag() (config/cache.php) whenever Cache Management saves either switch and on every Cache Management page load (so a switch already saved takes effect without re-saving). Off = no cache read/write, X-Image-Cache OFF, Cache-Control no-cache; on = identical to before (md5(url).jpg in data/image-cache, 7 days). Test: scripts/tests/cache_logic.php (45 pass, includes CLI runs of the real proxy). Same files for Liwonde; the flag is a runtime file, not committed. · patch lead
- BLOCKED (owner) · Delete or disable the Liwonde "test" staff account (username test, Manager, password Admin123, created 2026-10-23 for the opening stock count approval) before Liwonde goes live: weak password.
- BLOCKED (owner) · Owner-side security follow-ups from the sweep (not code): delete/rotate the error_log files on the live hosts; confirm APP_ENCRYPTION_SALT in each host .env; existing API keys keep GET access, writes to housekeeping/maintenance-schedules/room-photos/room-amenities need the rooms.manage scope; after deploy the live hosts get cache/.htaccess on first cache write; deploy then clear all caches on Cache Management.
- BLOCKED (owner) · Go-live steps: reconnect Facebook to the business Page; set up the email sender domain; add a backup admin email; check live server settings; set stock units (pieces per packet/tray) and do/approve the Rosalyn's opening count (CD39).

## Public site follows the business setup (gym-only, bar, shop, supermarket rehearsals)
- TODO · Home/footer/header hide room booking, room cards, gym and conference sections when their
  modules are off; booking.php "Booking Unavailable" page has a blank "Email:". · guest-site lead

## Wording per business type (gym, bar, shop rehearsals)
- TODO · One label set by setup: no "Restaurant & Bar", "Tap a dish", "Fire order", "Covers",
  "Hotel KPIs", "Front Desk", "Early/Late checkout" refund reasons, "POS & POS", "Beverages ·
  Beverages", raw category slugs on product cards, "ingredients" in shop stock screens; add a
  "damaged" stock-count reason. Hotels keep today's wording. · restaurant lead + ui-lead
- TODO · Reports shows the stock "Opening stock not recorded" banner with Stock off. · stock lead
- TODO · EOD tab labels ("Yield & guests / Housekeeping & more") hidden/renamed without rooms. · accounting lead

## Gym
- TODO · Persistent "owed back" list for cancelled gym accounts (credit owed shows once only). · accounting lead
- TODO · Member start date defaults to the hotel date, not the browser clock. · events-gym lead
- TODO · Daily sweep marks lapsed members 'expired' in the database (screens already use end date). · events-gym lead
- TODO · Class capacity + waiting list; Renew button; unpaid renewal blocks check-in (setting);
  slot reservation confirmation email and member-number check. · events-gym lead

## Till / stock / F&B
- TODO · Payments-page refund (finance-account-sync.php) also needs the shop-mode "damaged" choice (today a shop refund always restocks). · accounting lead
- TODO · Till (admin/pos.php) shop-mode void and refund dialogs: "Damaged goods (record as waste)" checkbox sending damaged=1 (server side shipped; owner decision 2026-10-22). · restaurant lead + ui-lead
- TODO · Stock-count surplus that settles a sold-at-zero deficit should also fill in the sale cost (deliveries already do). · stock lead
- TODO · Out-of-stock alert misses items that went negative with no batch ever received. · stock lead
- TODO · Till "Delivery history" says nothing sent when the email was logged. · restaurant lead
- TODO · "Reversed order still has an active payment" false alarm on a voided paid order. · accounting lead
- TODO · Split payment + settle takes ~9 s — profile and speed up. · restaurant lead
- TODO · Stock Dashboard "Settled today": hint that room service charged to rooms is excluded. · stock lead
- TODO · Per-product VAT: exempt / zero-rated flag for supermarkets (needs a column → owner OK'd as part of this batch; migration only). · accounting lead

## Conference venue rehearsal 2026-10-22 (P1/P2 first)
- TODO · F1 high: includes/conference-pricing.php:19 SHOW COLUMNS LIKE ? fails with native prepares → end_date/half_day_rate look missing: reschedule, multi-day, half-day pricing and multi-day clash all off. · events-gym lead
- TODO · F2 high: config/email.php:2397 catch (Exception) misses PDOException from logCancellationToDatabase → every conference cancel shows staff a raw SQL error, credit message lost. Catch Throwable, skip non-room types. · events-gym lead
- TODO · F3 high: overpayment's credit-note refund row makes the payment partially_refunded before the receipt step, which skips it → no receipt PDF/email (config/receipts.php:1336, account-record-payment.php:166-176). · accounting lead
- TODO · F4 high: Reports Aging/AR and Invoices "Outstanding" read only pending payment rows → conference/event/gym money owed shows 0 (EOD "Still owed" too). · accounting lead
- TODO · F5 medium: unconfirmed enquiries counted as receivables/revenue on accounting dashboard, dashboard, reports conference tab → count confirmed/completed only. · accounting lead
- TODO · F6 medium: conference/event quotations never written to the quotations register (no PDF kept, no expiry reminder). · events-gym lead + accounting
- TODO · F7 medium: conference quotation email shows literal {{logo_html}}/{{address}}; credit-note email shows {{address}} {{contact_phone}} {{guest_email}}. · events-gym lead + accounting
- TODO · F8 (latent, VAT-on-top mode) first quotation/public estimate show net; F9 conference sync lacks 'refunded' state; F10 conference_venue preset keeps bookings on (public site sells rooms) — decide with the public-site item; F11 off-module links/wording; F12 refund rows listed "Needs PDF"; F13 EOD payment mix gross of refunds, VAT column doesn't add up; F14 conference details modal lacks VAT/paid/balance and attendee/room edit; F15 smoke_test_booking leaves a tentative_booking_log row; F16 email wording. IDEAs: conference deposits setter, events reserving conference rooms, pre-event reminder for unpaid confirmed conferences.

## Hotel (No Restaurant) rehearsal 2026-10-22
- SHIPPED 74c93dc / Liwonde de25c55 · F1 P1 room quotations priced net in inclusive mode; F2 P1 invoice/statement room line net labelled "incl. VAT" (doesn't foot); F3 final payment after checkout leaves "BALANCE DUE" invoice, regenerate burns a number; F13 receipt email vs PDF VAT lines. · accounting lead
- SHIPPED 74c93dc / Liwonde de25c55 · F4 in-house type move leaves the old room occupied; F5 no same-type in-house move; F6 public split bookings not linked as a group; F7 last_cleaned_at never updates. · rooms lead
- TODO · scripts/tests/help_map.php fails on the gym pages in Rosalyn's because the gym module is off there (help is hidden for off modules) — the test should skip pages of switched-off modules (passes 1410/1410 on Liwonde). · patch lead
- TODO · admin/invoices.php per-payment regenerate still prints invoice wording on a fully paid booking (amounts and numbers correct). · accounting lead
- TODO · Notes from the F1-F7 QA: quotations.total_amount now gross for new/resent rows, older rows net (Reports pipeline mixes bases until resent); legacy admin-customised invoice template (config/invoice.php ~984) prints group room rows from net incl. levy (only if a custom template is used); public split groups now get one overdue reminder per group lead. · accounting lead
- TODO (batch) · F8 EOD arrivals/departures skip same-day checked-out; F9 folio F&B labelled "POS / Till Sales" with POS off; F10 Reports per-type occupancy denominator vs RevPAR, shared pair counted twice; F11 restaurant links with restaurant off; F12 POS/KDS/stock pages open by URL with modules off, "Run POS & KDS" install banner.

## Website & CMS audit 2026-10-22 (owner: "fix them all and add feature patch now like drag and drop")
- SHIPPED 3086be2 + e5cdd11 / Liwonde 3ba3973 + 4fae9af (2026-10-22) · F1 gallery actions don't clear cache (deleted photo stays as broken image ~1h); F6 footer adds ".php" to external/mailto/tel links; F2 Media Portal inactive items vanish for good; F3 inactive section header still renders default text; F4 settings cache not cleared from Media Portal; F5 gallery delete leaves ghost catalog row / may unlink shared image; F7 Rosalyn's page scaffold missing CSS; F8 Rosalyn's empty gallery image tile; F9 media delete not transactional, gallery edit uploads before validating; Page Management drift. · guest-site lead
- SHIPPED 3086be2 + e5cdd11 / Liwonde 3ba3973 + 4fae9af (feature, approved) · Gallery drag-to-reorder; Section Headers can add the code-only headers (contact_main, guest_services_main…); footer fallback FAQ without literal tags. · guest-site lead

## Installed app (PWA)
- TODO · 8-hour idle sign-out sends the person's unsent sales first (or warns on next sign-in). · pwa lead
- TODO · Clear the till cart after a sale is queued offline; timeout on network-first page loads;
  timeouts on till pollers; a station with a stale security token shows a message, not a frozen
  board; discard button (manager) for a permanently refused queued sale. · pwa lead

## UI requests
- TODO · End of Day action buttons clip at 390px; POS Accounting log amounts wrap at 1400px and the
  closed-cashier row looks blank; till header polish at narrow widths. · ui-lead

## Housekeeping of the code
- TODO · Parity pass between hotels: config/database.php cancellation log type, includes/room-
  management.php room-status error handling, admin/booking-settings.php,
  admin/section-headers-management.php (front-end files may differ by design). · patch lead
- TODO · KDS-by-user report counts rush/note rows as extra "fired". · restaurant lead

## Proposed (needs owner approval)
(none yet)
