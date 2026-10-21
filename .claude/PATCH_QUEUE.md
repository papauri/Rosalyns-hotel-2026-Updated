# Patch queue — owner-approved feature patches and security patches

Owned by `dept-patch-lead`. Owner approved this whole batch on 2026-10-22 ("Approve the monthly
role outs"), so these items are exempt from the feature freeze. Anything NOT on this list still
needs owner approval first (add it under "Proposed" with one line and ask).

Status: TODO · DOING · REVIEW · SHIPPED · BLOCKED (owner). One commit per item or per small group.
Owner lead = the department lead whose files it is; the patch lead plans, briefs, gates and ships.

## Security patches (standing — always allowed, highest priority)
- TODO · SWEEP 2026-10-22 findings (fix in this order; details in the sweep report):
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
