# Patch queue — owner-approved feature patches and security patches

Owned by `dept-patch-lead`. Owner approved this whole batch on 2026-10-22 ("Approve the monthly
role outs"), so these items are exempt from the feature freeze. Anything NOT on this list still
needs owner approval first (add it under "Proposed" with one line and ask).

Status: TODO · DOING · REVIEW · SHIPPED · BLOCKED (owner). One commit per item or per small group.
Owner lead = the department lead whose files it is; the patch lead plans, briefs, gates and ships.

## Security patches (standing — always allowed, highest priority)
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
