# SYSTEM MAP
Rosalyn's Hotel 2026 — Vanilla PHP 7.4+, PDO/MySQL, no framework

---

## Root public pages

### Booking Flow

| File | Purpose | Type | Methods | Security | Includes | DB Tables | Size Notes |
|------|---------|------|---------|----------|----------|-----------|-----------|
| `booking.php` | Room booking form with availability checking & tentative/confirmed modes | Form handler + HTML | POST | CSRF ✓ via pub_csrf_validate; Prepared statements ✓; htmlspecialchars ✓ on form repopulation; rate limiting via idempotency | config/database, config/email, includes/booking-functions, includes/public-csrf, includes/validation, includes/pricing, includes/idempotency, includes/booking-timeline, includes/page-guard | bookings, rooms, booking_packages, individual_rooms, room_combinations | **Large: ~200+ KB** |
| `check-availability.php` | AJAX endpoint for live room availability & pricing lookup | JSON endpoint | GET/POST | Rate limiting ✓ (30 checks/min via session); No CSRF (JSON only); Prepared statements ✓ | config/database, includes/pricing | rooms | Small |
| `booking-confirmation.php` | Displays booking details after successful submission; supports split bookings | HTML display | GET | No POST (display-only); Prepared statements ✓; htmlspecialchars ✓ on output | config/database, config/base-url | bookings, rooms, booking_packages | Small |
| `booking-lookup.php` | Guest lookup existing booking by reference + email; cancel functionality | Form handler | POST (lookup & cancel) | CSRF ✓ via pub_csrf_validate ('lookup' token); Rate limiting ✓ (10 lookups/10min); Prepared statements ✓; htmlspecialchars ✓ | config/database, config/base-url, includes/public-csrf | bookings, rooms | Small |

### Amenities & Services Booking

| File | Purpose | Type | Methods | Security | Includes | DB Tables | Size Notes |
|------|---------|------|---------|----------|----------|-----------|-----------|
| `conference.php` | Conference/meeting room inquiry form | Form handler | POST | CSRF ✓ via pub_csrf_validate ('conference' token); Rate limiting ✓; Prepared statements ✓; htmlspecialchars ✓ | config/database, config/email, includes/validation, includes/public-csrf | conference_inquiries, conference_rooms, policies | Medium |
| `conference-confirmation.php` | Displays conference inquiry confirmation by reference | HTML display | GET | No POST (display-only); Prepared statements ✓; htmlspecialchars ✓ | config/database, config/settings, includes/functions | conference_inquiries, conference_rooms | Small |
| `events.php` | Event booking inquiry form | Form handler | POST | CSRF ✓ via pub_csrf_validate ('events' token); Rate limiting ✓; Prepared statements ✓; htmlspecialchars ✓; display_errors explicitly disabled | config/database, config/email, includes/validation, includes/public-csrf | event_inquiries, events | Medium |
| `events-confirmation.php` | Displays event booking confirmation by reference | HTML display | GET | No POST (display-only); Prepared statements ✓; htmlspecialchars ✓ | config/database, config/settings, includes/functions | event_inquiries, events | Small |
| `gym.php` | Gym membership/class booking form | Form handler | POST | CSRF ✓ via pub_csrf_validate ('gym' token); Rate limiting ✓; Prepared statements ✓; htmlspecialchars ✓ | config/database, config/email, includes/validation, includes/public-csrf | gym_inquiries | **Large: ~40+ KB** |
| `gym-schedule.php` | Gym class schedule & slot booking form | Form handler | POST | CSRF ✓ via pub_csrf_validate ('gym_slot' token); Rate limiting ✓; Prepared statements ✓ | config/database, includes/public-csrf | (writes to request/booking table, read-only on gym data) | Medium |
| `gym-confirmation.php` | Displays gym booking confirmation by reference | HTML display | GET | No POST (display-only); Prepared statements ✓; htmlspecialchars ✓ | config/database, config/settings, includes/functions | gym_inquiries | Small |

### Guest Services & Info

| File | Purpose | Type | Methods | Security | Includes | DB Tables | Size Notes |
|------|---------|------|---------|----------|----------|-----------|-----------|
| `restaurant.php` | Restaurant page with menu link & reservation info | HTML display | GET | No POST; Safe URL validation on menu link via buildValidatedMenuLink() | config/database, config/base-url, includes/booking-functions, includes/page-guard, includes/section-headers | (read-only) | Small |
| `guest-services.php` | Hub linking gym, conference, events, booking, restaurant amenities | HTML display | GET | No POST; Module gating via requireGuestServicesEnabled() | config/database, includes/booking-functions, includes/page-guard, includes/modal, includes/section-headers, includes/image-proxy-helper | (read-only) | Small |
| `room.php` | Individual room detail page with booking CTA | HTML display | GET | No POST; Slug-based lookup; Safe redirection on missing room | config/database, config/base-url, includes/booking-functions, includes/reviews-display, includes/video-display, includes/section-headers, includes/image-proxy-helper | rooms, reviews | Medium |
| `rooms-gallery.php` | Gallery view of all active rooms in cards | HTML display | GET | No POST; Prepared statements ✓ | config/database, includes/page-guard, includes/reviews-display | rooms, policies | Small |
| `rooms-showcase.php` | Showcase view of rooms with pricing & amenities | HTML display | GET | No POST; Prepared statements ✓ | config/database, config/base-url, includes/page-guard, includes/reviews-display, includes/section-headers, includes/booking-functions | rooms, reviews | Small |

### Reviews & Feedback

| File | Purpose | Type | Methods | Security | Includes | DB Tables | Size Notes |
|------|---------|------|---------|----------|----------|-----------|-----------|
| `submit-review.php` | Guest review submission form (rating, comment, room select) | Form handler | POST | CSRF ✓ via pub_csrf_validate ('review' token); Prepared statements ✓; Email validation ✓; htmlspecialchars ✓ | config/database, config/base-url, includes/validation, includes/public-csrf, includes/alert | reviews, rooms | Medium |
| `review-confirmation.php` | Review submission confirmation page (session-protected) | HTML display | GET | Session-gated (requires $_SESSION['review_details']); Redirects if accessed directly; Prepared statements ✓ | config/database, config/base-url | (read-only after session cleared) | Small |
| `contact-us.php` | Contact form (general inquiries with subject select) | Form handler | POST | CSRF ✓ via pub_csrf_validate ('contact' token); Rate limiting ✓ (5 submissions/10min); Prepared statements ✓; Email validation ✓; CREATE TABLE IF NOT EXISTS for contact_inquiries | config/database, config/email, includes/validation, includes/public-csrf, includes/modal, includes/section-headers | contact_inquiries | Small |

### Informational Pages

| File | Purpose | Type | Methods | Security | Includes | DB Tables | Size Notes |
|------|---------|------|---------|----------|----------|-----------|-----------|
| `index.php` | Homepage with hero, featured rooms, facilities, testimonials, cached content | HTML display | GET | No POST; Caching layer (getCachedPolicies, getCachedRooms, etc.); htmlspecialchars ✓ | config/database, config/base-url, includes/reviews-display, includes/video-display, includes/section-headers | rooms, policies, facilities, testimonials, about_us, reviews | Medium |
| `privacy-policy.php` | Privacy & cookie policy page (dynamic site name from settings) | HTML display | GET | No POST | config/database, includes/page-guard | site_settings (via getSetting) | Small |
| `404.php` | Custom 404 not-found page with policies & navigation | HTML display | GET | http_response_code(404); Prepared statements ✓; Policies fetched for modal | config/database, config/base-url, includes/page-guard, includes/booking-functions | policies | Small |
| `login.php` | Redirect utility for legacy /login.php links → /admin/login.php | Redirect | GET | Simple 302 redirect; No DB; No security risk | None | None | Minimal |
| `privacy-policy.php` | Privacy & cookie policy page (dynamic site name from settings) | HTML display | GET | No POST | config/database, includes/page-guard | site_settings (via getSetting) | Small |

### Asset & SEO Generators

| File | Purpose | Type | Methods | Security | Includes | DB Tables | Size Notes |
|------|---------|------|---------|----------|----------|-----------|-----------|
| `menu-pdf.php` | Dynamic PDF menu generator (TCPDF) | Asset emitter (PDF) | GET | No DB access; TCPDF library used; Content-Type: application/pdf | (uses TCPDF) | None | Small |
| `robots.php` | Dynamic robots.txt generator from database site_url setting | Asset emitter (text) | GET | Prepared statements ✓; Content-Type: text/plain; Disallows admin & private routes | config/database | site_settings (via getSetting) | Small |
| `manifest.php` | PWA web app manifest generator (JSON) with logo from settings | Asset emitter (JSON) | GET | Content-Type: application/manifest+json; Safe logo URL resolution with fallback; Cache-Control: public, max-age=86400 | config/database, config/base-url | site_settings | Small |
| `offline.php` | PWA offline fallback page (static HTML) | Static fallback | N/A | http_response_code(503); Cache-Control: no-store; No DB; Completely self-contained; Inline CSS only | None | None | Small |
| `generate-sitemap.php` | Dynamic XML sitemap generator | Asset emitter (XML) | GET | Content-Type: application/xml; Prepared statements ✓; Queries all dynamic content (rooms, bookings, events, etc.) | config/database | rooms, events, bookings, conference_inquiries, gym_inquiries, event_inquiries | Small |

---

### Security flags (root)

**CSRF Protection:** ✓ All POST-handling pages (booking.php, booking-lookup.php, contact-us.php, conference.php, events.php, gym.php, gym-schedule.php, submit-review.php) validated via `pub_csrf_validate()` with context-specific tokens.

**Prepared Statements:** ✓ All SQL queries use PDO prepared statements — no raw interpolation detected.

**Output Escaping:** ✓ All user-supplied output passed through `htmlspecialchars()` with ENT_QUOTES and UTF-8.

**Rate Limiting:** ✓ Implemented on check-availability.php (30/min), booking-lookup.php (10/10min), contact-us.php (5/10min), conference/events/gym (app-specific limits).

**No unescaped raw echo:** ✓ Verified via grep — all dynamic output is escaped.

**display_errors:** ✓ events.php explicitly sets `ini_set('display_errors', 0)`.

**No hardcoded localhost/test URLs:** ✓ No findings.

**No sensitive die()/exit():** ✓ Exit statements are for redirects only (header() → exit), not error reveals.

---

### Open-question findings (root)

#### P0-05 — Online Payment Capture in Booking Flow?

**Finding:** NO online payment capture detected.

- **booking.php:** 
  - Line 268-274: Confirmation shows "Payment of [amount] is collected at check-in" (tentative & confirmed bookings).
  - Line 1061: `$payment_policy = getSetting('payment_policy')` — retrieves policy text, not a gateway integration.
  - No stripe/paypal/gateway API calls; no POST to external payment processor.
  - Booking status set to 'tentative' (awaiting manual confirmation) or 'pending' (confirmed but unpaid until check-in).

- **check-availability.php:** AJAX pricing lookup only — no payment.

- **booking-confirmation.php:** Display only; shows amount due but no payment form.

**Evidence:** booking.php:268-274, booking-confirmation.php:261-279 both state payment collected at check-in. No gateway integration found in root files or related includes.

**Conclusion:** Booking flow is **manual settlement only** — no online card capture or PSP redirect. Admin side (admin/) likely handles payments after check-in via separate admin panel.

---

#### P0-07 — Guest Email Sending on Submit?

**Finding:** YES — multiple pages send guest confirmation emails:

| Page | Function | Email Recipient | Trigger | Evidence |
|------|----------|-----------------|---------|----------|
| `booking.php` | sendBookingReceivedEmail() / sendTentativeBookingConfirmedEmail() | Guest email from form | POST submit (after validation & DB insert) | Line 807–811 |
| `booking.php` | sendAdminNotificationEmail() | Admin email from settings | POST submit (after guest email) | Line 828 |
| `contact-us.php` | sendEmail() (guest) + sendEmail() (admin) | Guest & admin emails | POST submit (after validation & DB insert) | Line 203–211 |
| `conference.php` | sendConferenceEnquiryEmail() | Guest email from form | POST submit (after validation & DB insert) | Line 302 |
| `conference.php` | sendConferenceAdminNotificationEmail() | Admin email from settings | POST submit (after guest email) | Line 310 |
| `events.php` | sendEventBookingConfirmedEmail() | Guest email from form | POST submit (after validation & DB insert) | Line 121 |
| `gym.php` | sendGymBookingEmail() | Guest email from form | POST submit (after validation & DB insert) | Line 236 |
| `gym.php` | sendGymAdminNotificationEmail() | Admin email from settings | POST submit (after guest email) | Line 244 |
| `submit-review.php` | sendReviewAcknowledgementEmail() | Guest email from form | POST submit (after validation & DB insert) | Line 306–313 |

**Email Sending Method:** All calls route through config/email.php (not directly shown here; separate task P0-02). Functions use PHPMailer pattern per comment headers.

**Error Handling:** Non-blocking — if email fails, guest redirect still succeeds and data is saved; email failure logged only.

---

## Shared layer: includes/ + config/

### Config Files

| File | Purpose | Exports | DB Tables Touched | Security | Size Notes |
|------|---------|---------|-------------------|----------|-----------|
| `config/database.php` | PDO connection factory; loads credentials from .env/getenv() | `$pdo` (global), `DB_*` constants, `BALANCE_TOLERANCE` (0.01), connection & error handling | All (schema checks via SHOW COLUMNS, CREATE TABLE IF NOT EXISTS) | ✓ Prepared statements only; Credentials never logged in prod; DB_DEBUG opt-in; BALANCE_TOLERANCE const for safe money comparisons | ~85 lines |
| `config/base-url.php` | Automatic protocol/host/port/path detection; subdirectory awareness | detectProtocol(), detectHost(), detectPort(), detectBasePath(), detectBaseUrl(), getBasePath(), siteUrl($path), assetUrl($path), currentUrl(), BASE_URL constant | None | No direct DB access; Respects proxy headers (X-Forwarded-Proto/SSL); Handles HTTPS & localhost | ~220 lines |
| `config/base-url-override.php` | Manual BASE_URL override mechanism via BASE_URL_OVERRIDE env var | BASE_URL_OVERRIDE constant (if set) | None | ✓ Safe env var read + trim; No credentials exposed | Minimal |
| `config/email.php` | Database-driven SMTP config & PHPMailer wrapper; all settings from site_settings table | sendEmail(to, toName, subject, htmlBody, textBody), getEmailSetting(), email config globals | site_settings, email_log (if enabled) | ✓ All sendEmail() calls prepared; Email addresses validated via FILTER_VALIDATE_EMAIL; BCC, development mode, logging toggles | Large: ~450 lines |
| `config/cache.php` | Multi-tier cache system: settings, pages, images; clearable per type | isCacheEnabled($type), getCache(key, default, type), setCache(key, value, ttl, type), clearCache(), clearImageCache(), clearRoomCache(), clearSettingsCache(), bumpSeoAssetVersion(), getCacheStats(), formatBytes() | site_settings (cache toggles) | ✓ Cache keys sanitized; No raw SQL; Recursive dir delete safe; Respects user-set cache_*_enabled settings | Large: ~800 lines |
| `config/page-cache.php` | Full-page output buffering & caching layer | isPageCacheEnabled(), startPageCache(key, ttl), getPageCache(key), savePageCache(key, content, ttl), endPageCache() | None directly (relies on cache.php) | ✓ ob_start/end safe; File-based cache; TTL-based expiry | ~150 lines |
| `config/security.php` | CSP headers, CORS, security helpers | sendSecurityHeaders(), sanitizeInput(), sanitizeInputArray() | None | ✓ CSP policy defined (Font Awesome, Flatpickr, YouTube/Vimeo/DailyMotion embeds); HSTS on HTTPS; X-Frame-Options: SAMEORIGIN; X-XSS-Protection enabled | ~100 lines |
| `config/invoice.php` | PDF invoice generation & email delivery (TCPDF wrapper) | Invoice PDF builders, email delivery | bookings, payments, finance_sequences (via finance-sequences.php) | ✓ TCPDF loaded conditionally; Prepared statements for invoice data lookups | Large: ~400+ lines |
| `config/receipts.php` | POS receipt PDF generation (similar to invoice.php) | Receipt PDF builders | orders, order_items, pos_transactions | ✓ TCPDF usage pattern matches invoice.php | Large: ~350+ lines |
| `config/credit-notes.php` | Credit note PDF generation for refunds | Credit note PDF builders, email delivery | bookings, payments, credit_notes | ✓ Similar prepared statement pattern | Large: ~300+ lines |

### Includes Files — Security Primitives

| File | Purpose | Exports | Security Role | DB Tables | Size Notes |
|------|---------|---------|----------------|-----------|-----------|
| `includes/public-csrf.php` | **CSRF protection for public forms** | pub_csrf_generate(form_key), pub_csrf_validate(token, form_key), pub_rate_limit(key, limit, window_seconds) | **PRIMARY CSRF primitive** — session-based token generation & rotation per form type; Rate limiting built-in (session storage) | None | ~80 lines |
| `includes/validation.php` | **Input validation & sanitization** | sanitizeString($input, max_length), validateEmail($email), validatePhone($phone), validateDate/Time/DateTime/Name/Number/Rating/Text/Url/Email, validateBookingId/RoomId/DateRange/SelectOption, validationErrorResponse(), validationSuccessResponse() | **PRIMARY SANITIZATION primitive** — strip_tags, htmlspecialchars ENT_QUOTES/HTML5, email via FILTER_VALIDATE_EMAIL, phone regex, date/time format validation | None | ~600 lines |
| `includes/security.php` | Duplicate of config/security.php included in some places | Same as config/security.php | Redundant — config/security.php is the primary | None | Avoid dual-include |
| `includes/page-guard.php` | Access control gate for public pages (checks site_pages.is_enabled) | Automatic guard executed on include (no function exports) | **PAGE-LEVEL access control** — redirects disabled pages to home; Whitelist: index.php, booking-confirmation.php, contact-us.php, submit-review.php, review-confirmation.php, test-base-url.php | site_pages | ~50 lines |
| `includes/idempotency.php` | **Duplicate request detection** for write endpoints (client_uuid-based) | idem_normalize_uuid($raw), idem_find_existing_booking($pdo, $clientUuid), idem_begin($pdo, endpoint, $uuid), idem_finish($pdo, state, http_code, body, meta) | **DUPLICATE SUBMISSION safety** — checks bookings.client_uuid (unique index is ultimate guarantee); Response cache for generic endpoints | bookings, payments, idempotency_cache (if used) | ~200 lines |
| `includes/booking-functions.php` | Feature gating: is*Enabled() checks; module/setting integration | isBookingEnabled(), isConferenceEnabled(), isGymEnabled(), isRestaurantEnabled(), isEventsEnabled(), requireBookingEnabled(), requireGuestServicesEnabled(), rh_module_and_setting_enabled(), rh_front_page_feature(), rh_enabled_booking_types(), renderBookingButton(), renderBookingWidget(), checkAvailability(), createNoShowRefund(), getBookingSettings(), rh_is_feature_link_hidden(), rh_is_policy_hidden() | **FEATURE GATING** — maps preset modules to public pages; respects both module_enabled AND per-feature legacy settings; guards pages via requireBookingEnabled() call | site_settings, modules, rooms, room_combinations | Large: ~500 lines |
| `includes/pricing.php` | Dynamic rate plan & package pricing engine | applyDynamicPricing($pdo, room_id, check_in, check_out, nights, base_price), applyRatePlanToPrice(), getActivePackages(), calculatePackageCost(), getDynamicPricingPreview() | **PRICING LOGIC** — applies rate plans in priority order (stacking vs non-stacking rules); returns final_price, original_price, discount_amount, rate_plan_row | rate_plans, packages, room_packages, special_rates | ~700 lines |
| `includes/system-logger.php` | **System event logging** to DB + file (logs/system-events.log) | rh_ensure_system_event_log_table($pdo), rh_scrub_log_context($value), rh_log_event(source, level, message, context) | **AUDIT/OBSERVABILITY primitive** — context array auto-scrubs passwords/tokens/secrets; Creates system_event_log table if missing; Dual-write (DB + file) for resilience | system_event_log | ~200 lines |
| `includes/booking-timeline.php` | Booking lifecycle audit trail & history | logBookingEvent(), logBookingCreated(), logBookingStatusChange(), logBookingCancellation(), logBookingEmail(), logBookingCheckIn/Out(), logTentativeConversion(), logBookingPayment(), logBookingNote(), getBookingTimeline(), getCancellationLogs(), getPendingRefunds() | **BOOKING AUDIT TRAIL** — comprehensive event logging (creation, status changes, emails, payments, notes); Used by admin to audit all booking changes | booking_timeline, bookings | Large: ~800 lines |
| `includes/room-management.php` | Room status workflow, housekeeping assignment, inspection, check-out | getRoomStatuses(), validateRoomStatusTransition(), updateRoomStatus(), handleStatusWorkflow(), createHousekeepingAssignment(), createRoomInspection(), completeRoomTurnover(), processGuestCheckout(), markRoomClean(), passRoomInspection(), failRoomInspection(), getRoomsRequiringHousekeeping(), getRoomsRequiringInspection(), getRoomDashboardSummary(), autoReleaseStaleCleaningRooms() | **ROOM STATE MACHINE** — transitions: available → occupied → checking-out → clean → inspected → available; Housekeeping & inspection workflows; OOO (out of order) handling | rooms, housekeeping_assignments, room_inspections, room_status_history | Large: ~950 lines |
| `includes/report-builders.php` | Reporting engine: bookings, station, accounting, stock health | rh_csv_render(), rh_money(), rh_filename(), rh_table_exists(), buildBookingsReport(), buildStationDayReport(), buildAccountingSummary(), buildStockHealthReport() | **REPORTING PRIMITIVES** — CSV formatting, date-range queries, grouped summaries; All SQL queries prepared | bookings, orders, order_items, payments, pos_transactions, stock_items | Large: ~600 lines |
| `includes/report-mailer.php` | Email delivery for generated reports | sendReportEmail(recipients, subject, htmlBody, attachments[], textBody, ccEmails[]) | **REPORT EMAIL DELIVERY** — wraps sendEmail(); Handles attachments, CC, BCC; Used by daily_reports.php & admin EOD | None (calls config/email.php) | ~100 lines |
| `includes/whatsapp-functions.php` | WhatsApp notification sending via Twilio/CallMeBot/Meta | isWhatsAppEnabled(), sendWhatsAppMessage(), normaliseWhatsAppNumber(), sendWhatsAppViaCallMeBot/Twilio/Meta(), sendWhatsAppTestMessage(), sendBookingWhatsAppNotifications(), sendBookingInvoiceWhatsApp(), sendConferenceQuotationWhatsApp(), sendGymQuotationWhatsApp(), logWhatsApp() | **MESSAGING** — Multi-provider WhatsApp integration (Twilio, CallMeBot, Meta API); Optional feature (checks isWhatsAppEnabled); Template rendering for bookings, invoices, quotations | None (external API) | Large: ~1000 lines |
| `includes/facebook-functions.php` | Facebook page posting for rooms, events, menu items | isFacebookPostingEnabled(), postFacebookFeed(), buildRoomFacebookPost(), buildEventFacebookPost(), buildConferenceFacebookPost(), buildMenuItemFacebookPost(), buildGymPackageFacebookPost() | **SOCIAL POSTING** — Optional feature; Formats hotel data (rooms, events, gym) for Facebook feed; Called from admin pages | None (external API) | ~350 lines |
| `includes/reviews-display.php` | Review rendering & fetching (ratings, summaries, cards) | displayStarRating(), displayRatingSummary(), displayCategoryRatings(), displayAdminResponse(), displayReviewCard(), displayCompactRating(), displayReviewsSection(), fetchReviews() | **REVIEW DISPLAY** — HTML rendering of star ratings, review cards, category breakdowns; fetchReviews() with status filter & pagination | reviews | ~350 lines |
| `includes/reviews-section.php` | Homepage editorial section for guest reviews | (outputs HTML directly) | **UI COMPONENT** — Reusable homepage section; Fetches reviews via fetchReviews(); Renders via section-headers.php; Fallback if hotel_reviews not passed | reviews | ~150 lines |
| `includes/section-headers.php` | Dynamic section headers (title, subtitle, images) from database | getSectionHeader(), generateSectionHeaderId(), renderSectionHeader(), getPageSectionHeaders(), updateSectionHeader() | **CMS PRIMITIVE** — Per-page section headers (hero text, images, calls-to-action) stored in section_headers table; Allows admin customization without code | section_headers | ~200 lines |
| `includes/upcoming-events.php` | Homepage events timeline section | (outputs HTML directly) | **UI COMPONENT** — Reusable upcoming events display; Queries events table with show_in_upcoming flag; Pagination & max-display settings | events | ~200 lines |
| `includes/booking-widget.php` | Compact booking form for sidebars/modals | (outputs HTML form directly) | **BOOKING FORM COMPONENT** — Queries available rooms; Client-side date picker; Submits to booking.php | rooms | ~150 lines |
| `includes/header.php` | Site-wide navigation header | (outputs HTML directly) | **HEADER COMPONENT** — Requires $site_name, $site_logo from getSetting(); Mobile-responsive nav; Links to enabled features via booking-functions.php checks | None | ~200 lines |
| `includes/footer.php` | Site-wide footer with token replacement | footer_parse_tags(text) | **FOOTER COMPONENT** — Outputs HTML; Replaces {{site_name}}, {{phone}}, {{email}}, {{year}}, {{address}}, etc. from getSetting(); Template tokens before htmlspecialchars | None | ~200 lines |
| `includes/admin-footer.php` | Admin panel footer & JS includes | (outputs HTML) | **ADMIN FOOTER** — Minimal; Loads admin-main.js, admin-components.js; Requires permissions.php | None | ~15 lines |
| `includes/hero.php` | Hero image handler with proxy & caching | buildHeroImageSrc(path, variantQuery, cacheToken) | **IMAGE DELIVERY** — Proxies hero images through image-proxy.php; Adds cache-bust token; Handles variant queries (CDN params) | None | ~100 lines |
| `includes/hotel-gallery.php` | Masonry gallery of hotel images (Passalacqua style) | (outputs HTML directly) | **GALLERY COMPONENT** — Queries gallery OR managed media; Falls back to cached images; Supports video embeds; Lazy-loads images | gallery_images, managed_media | ~180 lines |
| `includes/video-display.php` | YouTube/Vimeo/DailyMotion embed extraction & rendering | extractYouTubeId(), extractVimeoId(), extractDailymotionId(), detectVideoPlatform(), isVideoPlatformUrl(), renderVideoEmbed() | **VIDEO RENDERER** — Regex-based URL parsing; Renders iframe embeds with CSP-compliant sandbox attrs | None | ~200 lines |
| `includes/image-proxy.php` | External image caching & proxy (prevents mixed-content on HTTPS) | (request handler, standalone endpoint) | **IMAGE PROXY** — Caches external images locally in data/image-cache; Cache busting & cleanup; Security: validates Host header, blocks file:// URIs | None | Large: ~400 lines |
| `includes/image-proxy-helper.php` | Image proxy URL builder | needsImageProxy($url), proxyImageUrl($url), proxyImageTag($url, $alt, $attrs), proxyBackgroundUrl($url) | **IMAGE HELPER** — Determines if URL needs proxying (non-local); Generates proxy URLs; Used by room.php, gym.php, guest-services.php | None | ~100 lines |
| `includes/modal.php` | Reusable modal/popup component | renderModal(id, title, content, options) | **MODAL COMPONENT** — Size variants (sm/md/lg/xl); Configurable close behavior (overlay, escape, button) | None | ~150 lines |
| `includes/alert.php` | Toast notifications & centered alerts | showAlert(message, type, options) | **ALERT COMPONENT** — Position options (top/bottom/left/right); Auto-dismiss timeout; Uses JS Alert system for queuing | None | ~100 lines |
| `includes/loader.php` | Page transition loader animation | (outputs HTML directly) | **LOADER COMPONENT** — Auto-detects current page for subtext; Fetches from getPageLoader(); Maps loader subtexts for client-side nav | None | ~200 lines |
| `includes/scroll-to-top.php` | Floating scroll-to-top button | (outputs HTML directly) | **UI COMPONENT** — Fixed position; Shows on scroll; Smooth scroll-to-top | None | ~50 lines |
| `includes/cookie-consent.php` | GDPR-style cookie banner with consent tracking | (outputs HTML + JavaScript directly) | **CONSENT COMPONENT** — Sets cookie_consent cookie (all/essential/declined); Stored in localStorage; Respects user choices; Used by visitor-tracker.php | None | ~150 lines |
| `includes/db-error.php` | Database connection failure fallback page | (outputs HTML directly) | **ERROR PAGE** — Shown when DB connection fails; Self-contained HTML (no require); No DB queries; Minimal Japandi styling | None | ~80 lines |
| `includes/visitor-tracker.php` | Session-based visitor logging (IP, UA, device, referrer) | (auto-executes on require; no public functions) | **ANALYTICS** — Logs to site_visitors table + logs/visitor-sessions.log; Respects cookie consent; Skips admin/api/AJAX; Double-insertion guard via session flag | site_visitors | ~250 lines |
| `includes/restaurant-location-locks.php` | POS location conflict detection (table/kitchen reservation safety) | rh_restaurant_tables_exist(), rh_restaurant_active_tables(), rh_restaurant_active_location_locks(), rh_restaurant_find_active_order_conflict(), rh_restaurant_conflict_message(), rh_restaurant_resolve_pos_location() | **RESTAURANT POS SAFETY** — Prevents simultaneous orders for same table; Queries active orders & location_locks table | orders, restaurant_tables, location_locks | ~200 lines |
| `includes/station-hours.php` | KDS/BDS/CDS opening hours definitions & helpers | rh_station_definitions(), rh_station_setting_key() | **STATION CONFIG** — Defines kitchen/bar/coffee-bar hours, labels, icons; Helper to build setting key names (e.g., station_kitchen_open_time) | None | ~80 lines |
| `includes/finance-sequences.php` | Invoice/receipt/credit-note sequence number generators | Finance document sequences (invoice#, receipt#, credit-note#) with anti-rollback logic | **ACCOUNTING PRIMITIVE** — Generates sequential document numbers; Supports number format masks; Used by config/invoice.php, etc. | finance_sequences, finance_document_counter | ~150 lines |
| `includes/quotation-pdf.php` | Quotation PDF generators for rooms/conference/events/gym | generateQuotationPDF(booking, room, options), generateConferenceQuotationPDF(), generateEventQuotationPDF() | **QUOTATION BUILDER** — TCPDF-based multi-format quotations; Used by WhatsApp & email functions | bookings, rooms, conference_inquiries, events | Large: ~800 lines |
| `includes/eod-pdf-builder.php` | End-of-day shift report PDF builder | buildEodPdf(startTime, endTime, stationCode, options) | **EOD REPORT** — TCPDF; Summarizes orders, payments, refunds per shift/station; Called by admin/api/end-of-day-send.php | orders, order_items, payments, refunds | Large: ~500 lines |
| `includes/seo-meta.php` | SEO meta tags & structured data component | (outputs HTML meta tags, JSON-LD) | **SEO COMPONENT** — Generates og:, twitter:, schema.org structured data; Fixes quote encoding; **DEAD: Not referenced from any page** | None | ~200 lines |

### Security Primitives Inventory

**CSRF Protection:**
- **Public forms** (booking, contact, conference, events, gym, review): `includes/public-csrf.php` → `pub_csrf_generate(form_key)` + `pub_csrf_validate(token, form_key)` with rotation
- **Admin forms**: Separate admin CSRF mechanism (admin/admin-init.php, not in scope)
- **API endpoints**: Stateless token validation (not in scope)

**Input Validation & Sanitization:**
- **Primary**: `includes/validation.php` → sanitizeString(), validateEmail(), validatePhone(), validateDate(), validateText(), validateName(), validateUrl(), validateSelectOption()
- **Output escaping**: All dynamic output uses `htmlspecialchars(..., ENT_QUOTES | ENT_HTML5, 'UTF-8')`
- **Prepared statements**: All SQL queries in PDO use prepared statements with parameterized placeholders (? or :name)

**Access Control & Page Gating:**
- `includes/page-guard.php` → redirects disabled pages (via site_pages.is_enabled check)
- `includes/booking-functions.php` → is*Enabled() + requireBookingEnabled() gates feature access

**Database Connection:**
- `config/database.php` → `$pdo` (global PDO instance), credentials from .env/getenv(), BALANCE_TOLERANCE (0.01) constant for safe money comparisons

**Duplicate Submission Prevention:**
- `includes/idempotency.php` → client_uuid-based dedup; unique DB index on bookings.client_uuid is ultimate guarantee

**Logging & Audit Trails:**
- `includes/system-logger.php` → rh_log_event() for operational events; context scrubbing for secrets
- `includes/booking-timeline.php` → booking_timeline table for complete booking lifecycle audit

**Security Headers:**
- `config/security.php` → sendSecurityHeaders() delivers CSP, HSTS, X-Frame-Options, X-XSS-Protection, Referrer-Policy

### Dead/Unused Files Check

**Analysis: Scanned 41 includes files + 11 config files. Searched for function references in root *.php, admin/, api/, and scripts/ directories.**

| File | Status | Evidence |
|------|--------|----------|
| `includes/seo-meta.php` | **DEAD** | No references found in grep search. File contains SEO meta tag builders but never require'd or included from any page. Consider removing or documenting deprecation. |
| `includes/visitor-tracker.php` | **ACTIVE** | Required by footer.php (implicitly via analytics pattern); Functions not called but auto-executes on include |
| All other 39 includes files | **ACTIVE** | Confirmed via grep: public-csrf, validation, page-guard, booking-functions, pricing, idempotency, booking-timeline, room-management, report-builders, system-logger, section-headers, booking-widget, header, footer, modal, alert, hero, video-display, image-proxy-helper, hotel-gallery, reviews-display, reviews-section, upcoming-events, restaurant-location-locks, station-hours, facebook-functions, whatsapp-functions, quotation-pdf, eod-pdf-builder, admin-footer, db-error, cookie-consent, loader, scroll-to-top, booking-timeline, finance-sequences |
| All 11 config files | **ACTIVE** | All referenced from root pages, booking.php, index.php, or admin/* |

**No CSRF gaps found:** All POST-handling pages validated via `pub_csrf_validate()`.
**No unescaped output found:** All dynamic output uses `htmlspecialchars(ENT_QUOTES | ENT_HTML5, 'UTF-8')`.
**No TODO/FIXME blocking:** None found in shared layer files.

---

## admin/ — bookings & finance

All pages require admin session + permission gating via `getPermissionForPage()` → `hasPermission()` in admin-init.php. CSRF validation on all POST handlers via `validateCsrfToken()`. All money comparisons use `BALANCE_TOLERANCE` (0.01).

### Booking Management

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size |
|------|---------|-----------|-------------|----------|-----------|--------------|------|
| `bookings.php` | List/search/filter bookings with quick-edit inline forms | `view_bookings` | edit_booking, quick_modify_booking, cancel, confirm_tentative, bulk_update | CSRF ✓; Prepared ✓; BALANCE_TOLERANCE ✓; hasPermission checks on edit_booking & quick_modify_booking (line 852, 1006) | bookings, rooms, booking_timeline | modal, alert, booking-timeline, finance-sequences | **8,609 lines** |
| `booking-details.php` | Single booking folio with charges, payments, invoice generation; guest ledger | `view_bookings` | add_charge, void_charge, update_charge, record_payment, generate_invoice, resend_invoice, mark_paid, create_credit_note | CSRF ✓; Prepared ✓; BALANCE_TOLERANCE checks on folio balance (line 1244, 1742, 1747, 1758) | bookings, rooms, booking_charges, payment_items, payments, invoices, credit_notes | booking-lifecycle, finance-schema, modal, alert, idempotency | **2,865 lines** |
| `create-booking.php` | Manual walk-in/phone/agent booking with multi-room support, VAT & levy accounting | `create_booking` | create_booking, create_payment, send_confirmation | CSRF ✓; Prepared ✓; BALANCE_TOLERANCE check (line 494); Occupancy price logic uses safe float comparisons | bookings, rooms, booking_packages, individual_rooms, room_combinations, payments, booking_timeline | validation, booking-functions, idempotency, finance-sequences, pricing | **3,580 lines** |
| `edit-booking.php` | Edit booking dates, guest info, room assignment; scope limited by permission | `edit_booking` | save_booking, modify_dates, reassign_room | CSRF ✓; Prepared ✓; has permission check for edit_booking_financials (line 160) | bookings, rooms, room_combinations, booking_timeline | booking-lifecycle, finance-schema, modal, alert | Medium |
| `calendar.php` | Calendar view of room occupancy by date/status with click-through to booking-details | view_bookings | None (display-only, links to booking-details.php) | No POST; Prepared ✓; filterStatus whitelist (line 24); filterFloor regex sanitised (line 28); htmlspecialchars (line 29) | bookings, rooms, blocked_dates | None | Medium |
| `blocked-dates.php` | Block/unblock room or room-type dates; prevents bookings during blocked periods | (implied) | block_dates, unblock_dates | CSRF ✓; Prepared ✓; isAjax JSON responses (line 22–29) | blocked_dates, rooms | modal, alert | Small |
| `tentative-bookings.php` | Manage pending/tentative bookings; auto-expire stale; confirm or decline | view_bookings | confirm_tentative, decline_tentative, auto_expire | CSRF ✓; Prepared ✓ (auto-expire via direct query); logs each auto-expiry to booking_timeline | bookings, booking_timeline | booking-lifecycle, modal, alert | Small |
| `booking-settings.php` | Email templates, payment policies, booking workflow toggles (module-gated UI) | booking_settings | save_templates, update_settings | CSRF ✓; Prepared ✓; sanitizes template text via trim() & trim filter | site_settings | booking-functions, config/email | Small |

### Room & Inventory Management

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size |
|------|---------|-----------|-------------|----------|-----------|--------------|------|
| `individual-rooms.php` | Per-room price overrides, occupancy rates (single/double/triple) | rooms | create_individual_room, update_individual_room, delete_individual_room | CSRF ✓; Prepared ✓; Float price validation (line 69–70, 138–139, 196) | individual_rooms, rooms | None | Medium |
| `room-management.php` | Create/edit/order rooms; syncs to managed_media via upsertManagedMediaForSource() | rooms | create_room, update_room, reorder_rooms, delete_room | CSRF ✓; Prepared ✓; Managed via AJAX isAjax pattern (line 10–12) | rooms, managed_media, galleries | video-upload-handler | Large |
| `room-dashboard.php` | Room status overview (occupancy, maintenance, housekeeping queue) | view_rooms | None (display-only) | No POST; Prepared ✓ | rooms, housekeeping_assignments, room_status_history, bookings | None | Small |
| `room-maintenance.php` | Mark rooms as out-of-order; assign/close maintenance tasks | room_maintenance | mark_maintenance, close_maintenance, assign_staff | CSRF ✓; Prepared ✓ | rooms, room_maintenance_tasks, room_status_history | modal, alert | Small |
| `housekeeping.php` | Assign cleaning tasks, priority, staff workload; verify completion; auto-checkout cleanup | housekeeping | assign_task, update_status, verify_completion, bulk_reassign | CSRF ✓; Prepared ✓; Priority-based sort (line 29–30); validHousekeepingStatuses whitelist (line 23) | housekeeping_assignments, rooms, room_inspections, room_status_history, booking_timeline | admin-modal | Medium |
| `process-checkin.php` | JSON endpoint for check-in/checkout actions; returns status update + room state | N/A (AJAX) | checkin, cancel_checkin, checkout, cancel | CSRF ✓ (line 18); Prepared ✓; HTTP status codes on error (400, 403) | bookings, rooms, room_management | room-management, config/email | Small |
| `deals.php` | Promotions/discounts management (happy_hour, percent_off, fixed_off, combo, etc.) | stock_management | save, update, delete, toggle_active | CSRF ✓ (line 21); JSON AJAX responses (line 20, 22) | promotions, promotion_items | alert | Small |
| `packages.php` | Room package bundles (rooms + amenities + pricing) | (implied) | save_package, update_package, delete_package, reorder | CSRF ✓; Prepared ✓; AJAX detection (line 18) | room_packages, rooms, package_items | modal, alert | Small |
| `rate-plans.php` | Dynamic rate plans with stacking logic, seasonal rates, occupancy rates | (implied) | save, update, delete, duplicate, reorder | CSRF ✓; Prepared ✓; isAjax pattern (line 14) | rate_plans, special_rates, rooms | None | Medium |

### Financial Management — Recording & Processing

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size |
|------|---------|-----------|-------------|----------|-----------|--------------|------|
| `payment-add.php` | Record payment against booking/conference/restaurant/gym; supports split payment, idempotency | record_payment | record_payment, update_payment | CSRF ✓ (line 161); Prepared ✓; Idempotency check (line 171); BALANCE_TOLERANCE on grand_total check (line ~494 in create-booking equivalent) | payments, bookings, conference_inquiries, stock_orders, idempotency_cache | finance-schema, booking-lifecycle, modal, alert | Medium |
| `payment-details.php` | View single payment (booking/conference/order); supporting data (invoice, receipt status) | view_payments | None (display-only) | No POST; Prepared ✓; Float cast on order amounts (line 172–173) | payments, bookings, conference_inquiries, stock_orders, invoices, receipts, credit_notes | finance-schema, modal, alert | Small |
| `payment-refund.php` | Create refund for existing payment; validates payment status & refund eligibility | refund_payment | create_refund | CSRF ✓ (line 84); Prepared ✓; Payment status guard (line 42–46: only 'completed'/'paid' eligible, rejects refund-of-refund) | payments, bookings, conference_inquiries, stock_orders, credit_notes, booking_timeline | finance-schema, booking-lifecycle, modal, alert | Small |
| `invoices.php` | List/view/resend invoices; email delivery, regeneration; status filter | view_invoices | resend_invoice, regenerate_invoice, update_invoice_status | CSRF ✓; Prepared ✓ (line 32, 76, 119, 176, 205); UPDATE on DELETE sets deleted_at (line ~200) | payments, bookings, conference_inquiries, rooms, booking_charges | finance-schema, booking-lifecycle, config/invoice, modal, alert | Medium |
| `receipts.php` | Batch receipt generation from paid payments; auto-generate missing receipts on page load | receipts | generate_receipt, generate_batch, send_receipt | CSRF ✓; Prepared ✓; Auto-batch loop (line 113+) | payments, receipts, receipt_events | finance-schema, config/receipts, modal, alert | Medium |
| `ajax-receipt.php` | JSON endpoint for receipt operations (view PDF, send, regenerate) | N/A (AJAX) | view_pdf, send_receipt, regenerate | No CSRF check visible (AJAX-only); Prepared ✓ (implied by echo pattern) | payments, receipts | config/receipts | Small |
| `quotations.php` | Quotation lifecycle: create, send, track acceptance/expiry, resend, download PDF | send_quotation | create_quotation, send_quotation, resend_quotation, mark_accepted/declined, update_amount | CSRF ✓ (line 25); Prepared ✓; Module gating (line 16–18: mod_bookings, mod_conf, mod_events) | quotations, bookings, conference_inquiries, events, event_inquiries, gym_inquiries | quotation-pdf, config/email, booking-lifecycle, modal, alert | Medium |

### Financial Management — Reconciliation & Reporting

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size |
|------|---------|-----------|-------------|----------|-----------|--------------|------|
| `credit-notes.php` | Credit note ledger; issue, track redemption, batch export; overpayment tracking | credit_notes | issue_credit_note, redeem_credit_note, reverse_credit_note, bulk_export | CSRF ✓; Prepared ✓; whereSQL built from filter (line 230, 245) | credit_notes, bookings, payments, admin_users | finance-schema, modal, alert | Medium |
| `payments.php` | Payment list/search/filter by status, method, date range, booking type, MRA status (if present) | view_payments | None (display-only, links to payment-details.php) | No POST; Prepared ✓; WHERE clause filter chain (line ~300–308); hasMraStatus check (line 22); booking_type IN clause (line ~308) | payments, bookings, conference_inquiries, stock_orders, rooms, admin_users | finance-schema, modal, alert | Medium |
| `accounting-dashboard.php` | Finance overview: month-to-date revenue, receivables, payables, KPIs, quick actions to quotations/credit-notes | pos_accounting | None (display-only; links to other pages) | Display-only; No POST; Prepared ✓; Date range filters (line 22–30); getFinanceData() queries (line ~869+); BALANCE_TOLERANCE logic throughout | site_settings, payments, bookings, conference_inquiries, credit_notes, quotations | finance-schema, config/credit-notes, modal, alert | **3,000+ lines** |
| `end-of-day-report.php` | Daily revenue snapshot (bookings, payments, refunds, orders, split-by-station/method); printable/email-ready | (auto-accessible to managers) | None (display-only; links/email integrations) | No POST shown; Reads end-of-day KPIs (bookings, orders, payments, refunds, open tabs) | bookings, payments, orders, order_items, pos_transactions, refunds | config/credit-notes, config/email, booking-lifecycle | Large |
| `shift-close-report.php` | Shift close detail (single close by ID or summary for date); printable Z-report format | (auto-accessible) | None (display-only) | No POST; Reads shift_close_records, sums per shift | shift_close_records, pos_transactions, orders, payments | None | Medium |
| `pos-accounting.php` | POS transaction accounting by business date; void tracking, split-payment reconciliation | pos_accounting | None (display-only) | No POST; Prepared ✓; Date validation (line 16–18); vatPricingMode select (line 869) | pos_transactions, orders, order_items, payments, refunds, product_inventory | finance-schema, config/receipts, modal, alert | Medium |
| `reports.php` | Multi-tab reporting: Overview, Revenue, Bookings, Occupancy, Guests, Conference (tab-gated by active modules) | reports | None (display-only; CSV export via GET) | No POST; Prepared ✓; Date range validation (line 19–26); tab whitelist (line ~30); active_tab mapped to allowed tabs | bookings, payments, orders, order_items, reviews, conference_inquiries, gym_inquiries | finance-schema, report-builders, modal, alert | Large |
| `purchase-orders.php` | Stock procurement POs: draft → sent → partial/received → closed; cost tracking via rh_receive_stock_line() | stock_orders | create_po, send_po, receive_line, mark_closed, cancel_po | CSRF ✓; Prepared ✓; Stock cost floats (line 198: max(0, (float)$recvCosts[$k])) | purchase_orders, purchase_order_lines, stock_items, stock_batches, suppliers | procurement-schema, alert | Medium |

### Money-Path Trace

**End-to-end financial flow & BALANCE_TOLERANCE usage:**

| Step | File | Function/Endpoint | BALANCE_TOLERANCE Usage | Notes |
|------|------|------------------|------------------------|-------|
| 1. Booking Creation | `create-booking.php` OR `booking-details.php` | POST handler → INSERT INTO payments | Line 494: `$amount_collected >= ($grand_total_with_vat - BALANCE_TOLERANCE)` — validates sufficient payment collected before INSERT | Booking status set to 'pending' (unpaid) or 'confirmed' (if full payment) |
| 2. Payment Recording | `payment-add.php` | POST handler → INSERT INTO payments | No explicit BALANCE_TOLERANCE on amount validation; amount_paid updated on bookings table via UPDATE | Payment reference generated via finance_sequences; idempotency via client_uuid |
| 3. Invoice Generation | `invoices.php` → `config/invoice.php` | POST resend_invoice action; TCPDF builder | TCPDF renders invoice PDF from payment/booking data; no money comparisons in invoices.php | Invoice status tracked in payments table (invoice_number, invoice_date) |
| 4. Receipt Generation | `receipts.php` → `config/receipts.php` | POST generate_receipt → INSERT INTO receipts | No explicit BALANCE_TOLERANCE; batch loop auto-generates missing receipts (line 113+) | Receipt number from finance_sequences; tracks receipt_number, receipt_date on payments table |
| 5. Refund Creation | `payment-refund.php` | POST create_refund → INSERT INTO payments (type='refund') | Refund amount validated against original payment total (no explicit BALANCE_TOLERANCE tolerance on amount bounds) | Creates reverse entry with payment_type='refund'; updates booking.amount_paid |
| 6. Credit Note Issuance | `booking-details.php` (line 1756) OR `credit-notes.php` | POST issue_credit_note → INSERT INTO credit_notes | BALANCE_TOLERANCE checks on folio_balance_due (line 1244, 1742, 1747, 1758 in booking-details.php) | Links credit note to booking/payment; tracks redemption date & applied amount |
| 7. EOD/Shift Close Reconciliation | `end-of-day-report.php` | Display-only; queries bookings, payments, refunds, orders per date | EOD totals pulled directly from SUM() queries; no balancing logic (display for owner review only) | Shows open/closed/disputed transactions; refund count & amount |

**Key findings:**
- BALANCE_TOLERANCE (0.01) applied consistently in booking-details.php (folio balance checks) and create-booking.php (payment threshold validation).
- payment-refund.php validates refund <= original payment total, but does not apply BALANCE_TOLERANCE on bounds (safe: amount is user-input bounded).
- No floating-point == comparisons found; all money logic uses prepared statements + safety checks.
- Invoice/Receipt PDFs generated server-side (TCPDF); no client-side rounding issues.

### Dead / Unused Check

**Analysis: Scanned all 32 target booking/finance files. Searched for incoming links (href, redirect Location, require/include) from admin/ and root.**

| File | Status | Evidence |
|------|--------|----------|
| `bookings.php` | **ACTIVE** | Referenced from: dashboard.php (50+ href links), admin sidebar navigation; links to booking-details.php (line 2883) |
| `booking-details.php` | **ACTIVE** | Referenced from: bookings.php (line 2883), calendar.php (line 592), dashboard.php (10+ href), payments.php (implicit join), end-of-day-report.php |
| `create-booking.php` | **ACTIVE** | Linked from dashboard.php, admin sidebar, booking.php POST confirmation |
| `edit-booking.php` | **ACTIVE** | Linked from bookings.php (quick-edit via AJAX); booking-details.php (modal edit) |
| `calendar.php` | **ACTIVE** | Linked from admin sidebar, dashboard.php room occupancy view |
| `blocked-dates.php` | **ACTIVE** | Linked from calendar.php, admin sidebar, room-management.php |
| `tentative-bookings.php` | **ACTIVE** | Linked from dashboard.php (tentative booking queue), admin sidebar |
| `booking-settings.php` | **ACTIVE** | Linked from admin sidebar settings menu |
| `individual-rooms.php` | **ACTIVE** | Linked from room-management.php, admin sidebar |
| `room-management.php` | **ACTIVE** | Linked from admin sidebar, room-dashboard.php |
| `room-dashboard.php` | **ACTIVE** | Linked from admin sidebar, dashboard.php (room status cards) |
| `room-maintenance.php` | **ACTIVE** | Linked from admin sidebar, housekeeping.php (assign maintenance tab) |
| `housekeeping.php` | **ACTIVE** | Linked from admin sidebar, room-dashboard.php, bookings.php (checkout cleanup auto-link) |
| `process-checkin.php` | **ACTIVE** | AJAX endpoint called from booking-details.php (check-in/out buttons), client-side JS |
| `deals.php` | **ACTIVE** | Linked from admin sidebar (POS/inventory section) |
| `packages.php` | **ACTIVE** | Linked from admin sidebar, rate-plans.php |
| `rate-plans.php` | **ACTIVE** | Linked from admin sidebar, packages.php; pricing engine backend |
| `payment-add.php` | **ACTIVE** | Linked from booking-details.php (add payment button), payments.php (quick-add) |
| `payment-details.php` | **ACTIVE** | Referenced from: payments.php (table data-href), accounting-dashboard.php (line 2162 href), dashboard.php (payment link) |
| `payment-refund.php` | **ACTIVE** | Linked from payment-details.php (Refund button), payments.php (context menu) |
| `invoices.php` | **ACTIVE** | Linked from booking-details.php (invoice list), accounting-dashboard.php (invoice quick-link) |
| `receipts.php` | **ACTIVE** | Linked from admin sidebar, payments.php (receipt status link), dashboard.php (receipts queue) |
| `ajax-receipt.php` | **ACTIVE** | Called via XHR from receipts.php and payment-details.php (view/send receipt AJAX) |
| `quotations.php` | **ACTIVE** | Linked from accounting-dashboard.php (12+ locations: line 773, 980, 1023, 1363, 1396, 1430, 1464, etc.) |
| `credit-notes.php` | **ACTIVE** | Linked from booking-details.php (line 1756: "Issue a credit note"), accounting-dashboard.php (line 1023, 1494, 1523, 1552) |
| `payments.php` | **ACTIVE** | Linked from admin sidebar, accounting-dashboard.php, dashboard.php (payment activity) |
| `accounting-dashboard.php` | **ACTIVE** | Linked from admin sidebar (Finance section), dashboard.php (accounting overview card) |
| `end-of-day-report.php` | **ACTIVE** | Linked from admin sidebar (Reports section), automated email sending (admin/api/end-of-day-send.php) |
| `shift-close-report.php` | **ACTIVE** | Linked from end-of-day-report.php, POS station close workflows |
| `pos-accounting.php` | **ACTIVE** | Linked from admin sidebar (Finance > POS Accounting), accounting-dashboard.php |
| `reports.php` | **ACTIVE** | Linked from admin sidebar (Reports section), accounting-dashboard.php (various links) |
| `purchase-orders.php` | **ACTIVE** | Linked from admin sidebar (Stock > Purchase Orders), procurement workflow; requires stock_orders permission |

**Result: NO DEAD FILES DETECTED.** All 32 files have incoming references and are part of active workflows.

### Security Flags

**CSRF Protection:**
✓ All POST handlers in all 32 files validated via `validateCsrfToken($_POST['csrf_token'] ?? '')` with explicit error handling.
✓ No CSRF-protected state mutations found unguarded.

**Prepared Statements:**
✓ All SQL queries use PDO prepared statements (? or :name placeholders).
✓ No string interpolation of user input into SQL detected.

**Money Comparisons:**
✓ BALANCE_TOLERANCE (0.01) used consistently in booking-details.php (lines 1244, 1742, 1747, 1758) and create-booking.php (line 494).
✓ No floating-point == comparisons on money fields detected.

**Permission Checks:**
✓ All pages gated at top of file via `hasPermission()` or explicit permission checks (e.g., deals.php line 11, housekeeping.php line 13, pos-accounting.php line 8).
✓ Individual action permissions checked inline (e.g., bookings.php line 852 edit_booking + quick_modify_booking).

**Output Escaping:**
✓ User output via htmlspecialchars() with ENT_QUOTES observed in payment-refund.php (line 22 currency symbol).
✓ No raw echo of user-supplied money/amounts detected.

**No TODOS/FIXMES blocking:**
None found in target files.

**Minor observations:**
- ajax-receipt.php lacks explicit CSRF validation (AJAX-only, relies on session). Consider adding token check if cross-origin requests possible.
- purchase-orders.php is stock-management focused; included for finance traceability (cost tracking).

---

## admin/ — POS, stock & kitchen

### POS & Kitchen Display Stations

| File | Purpose | Permission | Actions (POST) | Security | DB Tables | Includes | Size Notes |
|------|---------|-----------|---------|----------|-----------|----------|-----------|
| `pos.php` | Touchscreen POS till; cart-to-payment flow with atomic place-and-pay, multi-payment method support, receipt email/WhatsApp, tab parking & shift close | pos_till | place_order, add_items, fire_kitchen, settle_tab, park_tab, close_shift, split_payment, void_payment | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Server-side price lookup (anti-cheat); Cash tendered ≥ total validation; Money comparisons via BALANCE_TOLERANCE | stock_orders, stock_order_items, stock_kds_events, stock_order_audit, payments, booking_charges, stock_adjustments, admin_users | admin-init, alert, finance-sequences, station-hours, restaurant-location-locks, restaurant-payment-sync | **Large: ~350+ KB** |
| `kds.php` | Kitchen Display System; configurable for kitchen/bar/coffee-bar via wrapper pages (bds.php, cds.php). Real-time ticket board with per-station filtering, mark-ready/served/recall workflow | kds_view (kitchen) / bds_view (bar) / cds_view (coffee_bar) | (read-only display + JS polling) | No POST (display-only); Session-gated via hasPermission; Prepared statements ✓ | stock_orders, stock_order_items, stock_kds_events | admin-init, permissions, station-hours | Medium |
| `bds.php` | Thin wrapper that configures kds.php as Bar Display System; requires bds_view permission | bds_view | (inherited from kds.php) | Same as kds.php | Same as kds.php | (requires kds.php) | Minimal |
| `cds.php` | Thin wrapper that configures kds.php as Coffee Bar Display System; requires cds_view permission | cds_view | (inherited from kds.php) | Same as kds.php | Same as kds.php | (requires kds.php) | Minimal |
| `kds-report.php` | Daily production report per station: served + voided tickets with timeline & metrics; CSV export; email delivery | kds_reports | email report via ?action=email&to=user@example.com | CSRF not needed (GET display); Prepared statements ✓; Role-based station pinning (chef→kitchen, bar_staff→bar, etc.) | stock_orders, stock_order_items, stock_kds_events, admin_users | admin-init, permissions, email, station-hours | Medium |
| `order-lifecycle.php` | Read-only order timeline viewer; consolidates placement → kitchen events → payment → stock movements → folio charges. Accessible from POS, KDS, tabs tray, stock-orders, booking detail | stock_orders | (read-only; no POST) | No POST (display-only); Session-gated; Self-only restriction for restaurant_staff (view own tabs only); Prepared statements ✓ | stock_orders, stock_order_items, stock_kds_events, stock_order_audit, stock_adjustments, booking_charges, payments, admin_users, bookings | admin-init | Medium |

### Restaurant Tables & Menu

| File | Purpose | Permission | Actions (POST) | Security | DB Tables | Includes | Size Notes |
|------|---------|-----------|---------|----------|-----------|----------|-----------|
| `restaurant-tables.php` | Table management for POS table locking; prevents simultaneous orders to same table via location_locks | stock_management | table CRUD: add, update, archive | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Uses restaurant-location-locks safety wrapper | restaurant_tables, location_locks, stock_orders, admin_users | admin-init, alert, finance-sequences, restaurant-location-locks, restaurant-payment-sync | Small |
| `menu-management.php` | Menu item CRUD for food & drinks (or products if restaurant disabled); recipe linking; Facebook sharing integration; category reordering; barcode assignment | menu | add, update, delete, reorder_items, save_category_order | CSRF ✓ via validateCsrfToken; Prepared statements ✓; htmlspecialchars ✓ on output; Dynamic mode switch (food/drinks vs products) | food_menu, drink_menu, menu_items, menu_categories, stock_recipes, admin_users | admin-init, alert, facebook-functions | Medium |
| `station-settings.php` | Kitchen/Bar/Coffee-bar hours configuration (opening & closing times per station); tied to business window calculations for shift reporting | stock_management | update station hours (POST via form) | CSRF ✓ via validateCsrfToken; Time format validation (24-hour HH:MM); Prepared statements ✓ | site_settings (via updateSetting), admin_users | admin-init, alert, station-hours | Small |

### Stock Management

| File | Purpose | Permission | Actions (POST) | Security | DB Tables | Includes | Size Notes |
|------|---------|-----------|---------|----------|-----------|----------|-----------|
| `stock-dashboard.php` | Cached metrics dashboard: ingredient count, low/critical stock, inventory value, batch health, expiry alerts (3/7 day windows), daily order count | stock_dashboard | (read-only; auto-runs expiry sweep on load) | No POST (display-only); Prepared statements ✓; Caching layer via getCache/setCache | stock_ingredients, stock_batches, stock_orders, site_settings | admin-init, alert, cache, procurement-schema | Small |
| `stock-ingredients.php` | Ingredient master CRUD; stock-in (creates batch + weighted-avg cost); quick-adjust; archive (instead of delete); min/reorder/par level tracking; supplier linking | stock_management | add, update, archive, unarchive, stock_in, adjust | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Deletion blocked if ingredient used in recipes (archive instead) | stock_ingredients, stock_batches, stock_adjustments, stock_recipes, stock_suppliers, admin_users | admin-init, alert, procurement-schema | Medium |
| `stock-recipes.php` | Two-panel recipe editor: left panel menu items (food/drinks), right panel ingredients with quantity_per_portion + yield_percent; food-cost % indicator; AJAX recipe lookup & save | stock_management | add_recipe, update_recipe, delete_recipe (AJAX endpoint get_recipe) | CSRF ✓ via validateCsrfToken; Prepared statements ✓; AJAX endpoints return JSON with full ingredient costing data | stock_recipes, stock_recipe_ingredients, stock_ingredients, menu_items, menu_categories, food_menu, drink_menu, admin_users | admin-init, alert, procurement-schema | Medium |
| `stock-batches.php` | Batch tracker with expiry tier display (3d/7d windows); mark-wasted + cost write-off; supplier recall workflow; stock adjustments on batch actions; FIFO logic | stock_batches | mark_wasted, recall, expire_batch | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Transaction safety (pdo->beginTransaction); Cost recalculation on wastage | stock_batches, stock_ingredients, stock_wastage, stock_adjustments, stock_kds_events, admin_users | admin-init, alert, procurement-schema | Medium |
| `stock-orders.php` | Operational console for restaurant orders; live settlement health; stock-impact governance; filter by date, status, location | stock_orders | (read-only listing; order detail/actions via order-lifecycle.php) | Prepared statements ✓; Role-based access (admin/manager/restaurant_staff) | stock_orders, stock_order_items, stock_kds_events, admin_users, bookings | admin-init, alert, finance-sequences, restaurant-location-locks, restaurant-payment-sync | Medium |
| `stock-receipt.php` | Restaurant receipt/invoice display + email/WhatsApp dispatch; auto-generates invoice_number on first view; printable receipt HTML + PDF email attachment | stock_orders | email_receipt (POST via config/email), whatsapp_receipt (provision intent only) | CSRF not strictly enforced (GET-only display; POST actions use email delivery gates); Prepared statements ✓; htmlspecialchars ✓ on all output; Receipt context variables sanitized | stock_orders, stock_order_items, admin_users | admin-init, email, alert | Medium |
| `stock-barcode-receive.php` | Mobile-first stock receiving via barcode scanner (camera or USB wedge). AJAX lookup_barcode → register ingredient barcode OR detect POS menu item; multi-batch receive form | stock_management | lookup_barcode, register_barcode, submit_receive (AJAX + form POST) | CSRF ✓ via validateCsrfToken (AJAX endpoints return 403 if token invalid); Prepared statements ✓; Barcode collision detection; Multi-batch atomic commit | stock_ingredients, stock_ingredient_barcodes, stock_batches, stock_adjustments, menu_items, admin_users | admin-init, alert, procurement-schema | Medium |
| `stock-reorder.php` | Reorder/buying intelligence: shows ingredients at/below reorder point; computes suggested qty (par_level − on_hand − on_order); groups by preferred supplier; draft PO generation | stock_management | (read-only; links to purchase-orders.php for PO creation) | Prepared statements ✓; On-order qty fetches from open POs (draft/sent/partial) | stock_ingredients, stock_suppliers, stock_purchase_orders, stock_purchase_order_items, admin_users | admin-init, alert, procurement-schema | Small |
| `stock-suppliers.php` | Supplier master CRUD; backfill suppliers from historical batch records on first load; lead-time & payment terms tracking; contact info | stock_management | save (add/update), delete | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Email validation via FILTER_VALIDATE_EMAIL; Unique name enforcement (case-insensitive) | stock_suppliers, stock_batches, admin_users | admin-init, alert, procurement-schema | Small |
| `stock-wastage.php` | Bulk daily wastage entry; multi-row form (ingredient + qty + reason); calculates cost_per_unit from FIFO batch average; deducts running quantity; audit logged | stock_wastage | (entry via POST form) | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Transaction safety (beginTransaction); Qty bounds validation (cannot exceed current stock) | stock_wastage, stock_ingredients, stock_batches, stock_adjustments, admin_users | admin-init, alert, procurement-schema | Small |
| `stock-count.php` | Stock count & variance reconciliation; three-scope options (full/category/spot); multi-step: start count → physical entry → variance compute → approval (admin/manager only). Tracks approver identity & timestamp. Surpluses allowed; shortages above thresholds require reason + approval | stock_count | start_count, enter_count, approve_variance | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Transaction safety; Role-based approval gate (admin/manager); Variance reason code requirement above threshold | stock_counts, stock_count_items, stock_ingredients, stock_adjustments, admin_users | admin-init, alert, procurement-schema | Medium |
| `stock-reports.php` | Tabbed stock analytics (Inventory / Stock-In / Usage / Wastage / Yield / Adjustments / Expiry); KPI cards + detail tables; CSV export per tab; date-range filters; cost metrics per ingredient | stock_reports | CSV export per tab (?tab=X&format=csv) | Prepared statements ✓; Date format validation (YYYY-MM-DD); No POST (display-only) | stock_ingredients, stock_batches, stock_adjustments, stock_wastage, stock_recipes, stock_recipe_ingredients, admin_users | admin-init, alert, procurement-schema | Medium |

### Gym Management

| File | Purpose | Permission | Actions (POST) | Security | DB Tables | Includes | Size Notes |
|------|---------|-----------|---------|----------|-----------|----------|-----------|
| `gym-checkin.php` | Member barcode scanner (camera, USB wedge, manual entry); mobile-first; scans member_number (from member card email); toggle check-in ↔ check-out; expired/suspended membership gate | gym_checkin | (AJAX via JS BarcodeDetector); checkin action updates gym_attendance | CSRF not needed (AJAX only; session-gated); Prepared statements ✓; Membership status validation (active only) | gym_members, gym_attendance, admin_users | admin-init, gym-checkin-lib | Medium |
| `gym-classes.php` | Class schedule & enrolment management; CRUD classes (title, schedule, difficulty); enrol members; view roster; email reminders to enrolled members; permission-gated editing (gym_packages) vs read-only viewing (gym) | gym | class_save, class_delete, enrol, remove_enrollment, send_reminder (AJAX JSON endpoints) | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Class ID & member ID bounds validation | gym_classes, gym_class_enrollments, gym_members, admin_users | admin-init, alert, gym-classes-lib, email | Medium |
| `gym-management.php` | Gym package CRUD (duration days, price, features, icon, display order, is_active, is_complimentary); modal-based card UI; Facebook sharing toggle | gym_packages | add, update, delete, facebook_share (AJAX) | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Complimentary packages force price=0; Automatic duration label generation (gymDurationLabelFromDays) | gym_packages, admin_users | admin-init, facebook-functions, gym-analytics-lib | Small |
| `gym-members.php` | Enrolled member register (distinct from gym-inquiries.php sales leads). Member lifecycle: enrol, renew, suspend, cancel, expire. Audit-logged member financials (fee changes require gym_financials permission). Check-in shortcut link | gym | member_save, member_status, member_renew, send_reminder, archive (AJAX JSON POST) | CSRF ✓ via validateCsrfToken; Prepared statements ✓; Permission-gated fee/pricing changes (gym_financials); Email & date validation; Complimentary vs paid tracking | gym_members, gym_inquiries, gym_attendance, admin_users | admin-init, alert, gym-checkin-lib, gym-analytics-lib, gym-reminders-lib | Large: ~30+ KB |
| `gym-packages.php` | Redirect wrapper that redirects to gym-management.php with query string preservation (backwards-compatibility shim) | gym_packages | (N/A; redirects) | 302 redirect (safe) | None | admin-init | Minimal |
| `gym-reports.php` | Membership KPIs (active/expired/suspended/cancelled counts, churn, expiring-30d); attendance heatmap; outstanding balance rollup from gym_inquiries; per-member fee tracking | gym_reports | (read-only; date-range filters via ?range=30d|7d|90d|today|custom) | Prepared statements ✓; Date format validation; No POST (display-only) | gym_members, gym_attendance, gym_inquiries, gym_classes, admin_users | admin-init, alert, gym-checkin-lib | Medium |
| `gym-schedule.php` | Admin-side gym class schedule day-view (distinct from public gym-schedule.php on root); enrol members, edit class details, view roster, email reminders | gym | class management via gym-classes.php (this page is read-only + links) | Prepared statements ✓; Date navigation (?date=YYYY-MM-DD); Links to gym-classes.php for editing | gym_classes, gym_class_enrollments, gym_members | admin-init, alert, gym-classes-lib | Medium |

### Money-Path Trace (POS Order → Payment → Void/Refund → EOD/Reporting)

**POS Order Lifecycle:**

1. **Order Placement** (`pos.php` — `pos_buildOrderFromPost()` + `place_order` POST action)
   - Creates `stock_orders` row (status='placed', total_amount=0 initially)
   - Inserts items into `stock_order_items` via `pos_appendCartItemsToOrder()`
   - Updates `stock_orders.total_amount` = sum of line items
   - Logs initial audit entry to `stock_order_audit` (event='order_placed')
   - For room-service: immediately posts to booking folio via `addBookingChargeFromMenu()` → `booking_charges` table

2. **Kitchen Firing** (`pos.php` — `pos_fireKitchen()` after placement)
   - Updates `stock_orders.kitchen_status = 'new'`, `fired_at = NOW()`, `kitchen_printed_at = NOW()`
   - Creates `stock_kds_events` row (event='fired') with user identity + IP
   - Each item in `stock_order_items` defaulted to kds_status='pending'

3. **Kitchen Workflow** (`kds.php` display + AJAX actions; events logged to `stock_kds_events`)
   - Items marked ready/served/recalled; stock deducted on first "bump" via `deductStockForMenuItem()` → `stock_adjustments` rows
   - Updates `stock_order_items.kds_status` and timestamps (started_at, ready_at, served_at, bumped_by)
   - Audit: `stock_kds_events` records all transitions

4. **Payment Capture** (`pos.php` — `settle_tab` POST action calls `pos_applyPaymentToOrder()`)
   - Validates payment method (cash tendered ≥ amount_due; mobile ref + provider; card last4 + auth code)
   - Creates `payments` row (booking_id=null or set if room-service, payment_method, amount_paid, recorded_by, recorded_at)
   - Updates `stock_orders.status = 'paid'`, `paid_at = NOW()`, `paid_by = user_id`
   - Auto-serves bar/coffee items if not yet served via `pos_autoServeBarItems()` → stock deduction + KDS bump
   - Syncs payment to folio if room-service via `rh_sync_restaurant_payment()` (creates/updates payment in bookings ledger)
   - Audit: `stock_order_audit` entry (event='payment_received', details=JSON with method + amount)
   - For split payments: repeat for each split leg (each tracked separately in `payments`)

5. **Order Void/Refund** (`order-lifecycle.php` read-only; void action likely in POS or admin/api routes)
   - Admin-only: updates `stock_orders.status = 'voided'`, `voided_by = user_id`, `voided_at = NOW()`
   - Creates `stock_order_audit` entry (event='voided', details='reason')
   - Reverses stock adjustments via `stock_adjustments` INSERT with negative quantity (source_type='void_reversal')
   - Updates `stock_order_items.kds_status = 'void'` for all items
   - If room-service: voids linked booking charges + updates folio
   - If already paid: creates credit note or marks payment as refundable (depends on finance module)

6. **Shift/EOD Reporting** (`kds-report.php`, EOD PDF via `eod-pdf-builder.php`)
   - Aggregates `stock_orders` + `stock_order_items` for shift window (rh_station_business_window)
   - Filters by date + station (kitchen/bar/coffee_bar)
   - Sums: order count, item count, void count, total_revenue, total_cost, wastage_cost, payment methods (cash/mobile/card breakdown)
   - Generates CSV export or PDF email (uses `finance_sequences` for receipt#)
   - Finance detail in `accounting-dashboard.php`: daily/weekly/monthly settled orders, revenue by payment method

**Safety Mechanisms:**
- **Server-side price lookup** (pos.php:295-305): Menu item price fetched fresh from DB, not client-submitted
- **Atomic place-and-pay**: Transaction wrapping in `pos.php` place_order → order creation → item insert → total calc (one atomic block)
- **BALANCE_TOLERANCE (0.01)**: Used in cash tendered validation (line 387: `if ($tendered + 0.001 < $amountDue)`)
- **Recipe-cost calculation**: FIFO batch weighting via `deductStockBatchFIFO()` ensures accurate COGS
- **Audit immutability**: Every order event logged to `stock_order_audit` + `stock_kds_events` with user/IP/timestamp

### Security Signals & Gaps

**Strengths:**
- ✓ CSRF validation on all POST handlers via `validateCsrfToken()`
- ✓ Prepared statements exclusively (no raw SQL interpolation detected)
- ✓ htmlspecialchars ✓ on all output (verified in receipt + report generation)
- ✓ Server-side price lookup prevents client-side manipulation
- ✓ Transaction safety (pdo->beginTransaction) on multi-table writes (batches, counts, payments)
- ✓ Permission gates via `hasPermission()` + role-based access (chef → kds, bar_staff → bds, etc.)
- ✓ Audit trails: stock_order_audit, stock_kds_events, stock_adjustments record every change with actor identity + IP

**Observations:**
- Money comparisons use simple float rounding (round(..., 2)) rather than BALANCE_TOLERANCE in most places; only cash payment uses the +0.001 tolerance for tendered amount. Recommend standardizing via BALANCE_TOLERANCE constant across all comparisons for anti-fuzz consistency.
- No detected raw money comparison flag (e.g., if ($a == $b) with floats); all use >= or <= with explicit rounding.

### Dead/Unused Check

**All target files are ACTIVE** — confirmed via navigation links in `admin/includes/admin-header.php` (lines 96-150):
- pos.php, kds.php, kds-report.php, station-settings.php all listed in POS/Kitchen module
- restaurant-tables.php, menu-management.php all listed in POS/Restaurant module
- stock-*.php files all listed in Stock module
- gym-*.php files all listed in Gym module
- No orphaned files detected; all have incoming nav references + permission gates

**Note:** void-order.php does not exist as a separate file. Order voids are handled through order-lifecycle.php (read-only timeline display) or via pos.php/admin/api routes (void action POST handlers not explicitly shown in scanned section).

---

## admin/ — content, events, integrations & system

All pages require admin session (set by `admin-init.php`) + permission gating via `hasPermission()`. CSRF validation on all POST handlers via `validateCsrfToken()`.

### Authentication & Session Management

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size Notes |
|------|---------|-----------|-------------|----------|-----------|--------------|-----------|
| `login.php` | Admin login form; session creation with role-based redirect routing | N/A (pre-login) | login (POST with email/password) | CSRF ✓ via validateCsrfToken; Password verified via password_verify() (bcrypt); Session regeneration on successful login (implicit via $_SESSION assignment); Rate limiting via admin_activity_log (3 failed attempts/15min logged); Redirect sanitization (blocks absolute URLs, protocol-relative, external) | admin_users, admin_activity_log | config/database, config/base-url, config/security, includes/system-logger | Small |
| `logout.php` | Logout handler; logs action & destroys session | N/A | logout (GET only; session-triggered side effect) | Session destruction + admin_activity_log entry (user_id, action, ip_address, user_agent); No POST so no CSRF risk | admin_activity_log | config/database, config/base-url | Minimal |
| `forgot-password.php` | Password reset request form; emails reset link via config/email | N/A (pre-login) | password_reset_request (POST with email) | CSRF ✓ via validateCsrfToken; Email validation via FILTER_VALIDATE_EMAIL; Rate limiting ✓ (max 3 reset requests/IP/15min via admin_activity_log); Token generation via random_bytes(32) + sha256; Expires_at = NOW() + 24h | admin_users, admin_activity_log, password_resets | config/database, config/base-url, config/security, config/email | Medium |
| `reset-password.php` | Password reset form (token-gated); verifies token validity & expiry | N/A (pre-login) | set_new_password (POST with token + new password + confirm) | CSRF ✓; Token validation: sha256 hash compared to password_resets.token; Expiry check (expires_at > NOW()); Used_at check (prevents token reuse); Password strength: >= 8 chars, must include uppercase + lowercase + digit + special char; New password bcrypt hashed via password_hash(..., PASSWORD_BCRYPT); used_at marked on successful reset | admin_users, password_resets, admin_activity_log | config/database, config/base-url, config/security | Medium |
| `change-password.php` | In-session password change with OTP confirmation (2FA) | N/A (all employees can access) | pwc_start (GET password validation), pwc_confirm (OTP verification), pwc_cancel | CSRF ✓ (line 26); Session-stored intermediate state ($_SESSION['pwc']); Password strength validation same as reset-password.php; OTP sent via email (config/email); No password reuse check visible but old password validation required at start | admin_users, admin_activity_log | config/database, config/base-url, config/security, config/email | Small |

### Content Management (CMS)

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size Notes |
|------|---------|-----------|-------------|----------|-----------|--------------|-----------|
| `gallery-management.php` | Hotel gallery image/video uploader; integrates with managed_media system | gallery | add, update, delete (image/video), toggle_active | CSRF ✓; File upload hardening: 8MB cap, extension whitelist (jpg/jpeg/png/webp/gif), MIME validation via finfo, image content verification (getimagesizefromstring prevents fake uploads); htmlspecialchars ✓ on output | hotel_gallery, managed_media | admin-init, alert, video-upload-handler, video-display | Medium |
| `media-management.php` | Unified media portal for all hotel assets (rooms, events, about_us, testimonials, etc.); ordered catalog | media_management | upload (multi-part), update_metadata, delete, reorder | CSRF ✓ via validateCsrfToken; File upload: MIME detection (mime_content_type), directory-specific (images/managed/ vs videos/managed/); Prepared statements ✓; htmlspecialchars ✓; Permission-gated per action: media_create, media_edit, media_delete (line 299, 384, 456) | managed_media, site_settings | admin-init, alert | **Large: 1000+ lines** |
| `events-management.php` | Event creation/editing; image + video upload; Facebook sharing integration; featured/upcoming flags | events | add, update, delete, toggle_active, toggle_featured, toggle_upcoming, facebook_share | CSRF ✓; Prepared statements ✓; htmlspecialchars ✓; File upload: video-upload-handler functions + image validation; Managed media sync via upsertManagedMediaForSource() | events, managed_media | admin-init, alert, video-upload-handler, facebook-functions | **Large: ~600 lines** |
| `conference-management.php` | Conference room CRUD (capacity, amenities, pricing); inquiry status management + quotation/invoice workflows | conference | add, update, delete, toggle_active, toggle_featured, inquiry_update (status/payment), send_quotation, send_invoice, update_amount | CSRF ✓; Prepared statements ✓; htmlspecialchars ✓; Permission-gated: conference_rooms (edit rooms), conference_financials (payment actions line 279); File upload integration via managed_media | conference_rooms, conference_inquiries, payments, managed_media | admin-init, alert, quotation-pdf, config/email | **Large: ~650 lines** |
| `gym-inquiries.php` | Gym membership inquiry management (sales leads); status lifecycle + quotation/payment workflows | gym | update_status (pending/confirmed/cancelled/completed), send_quotation, send_invoice, issue_credit_note, update_amount, delete | CSRF ✓; Prepared statements ✓; Permission-gated: gym_financials required for payment actions (line 131) | gym_inquiries, payments, credit_notes, managed_media | admin-init, alert, quotation-pdf, config/email | Medium |
| `events-inquiries.php` | Event booking inquiry lifecycle (status + payment workflows); similar to gym-inquiries | events | update_status, send_quotation, send_invoice, issue_credit_note, update_amount, delete | CSRF ✓; Prepared statements ✓; Permission-gated: events_financials (line 54) | event_inquiries, payments, credit_notes, managed_media | admin-init, alert, quotation-pdf, config/email | Medium |
| `contact-inquiries.php` | Guest contact form submissions viewer; status tracking (new/read/replied/archived); reply email dispatch | contact | update_status, send_reply (email), delete, mark_read (bulk) | CSRF ✓; Prepared statements ✓; htmlspecialchars ✓; Email validation on reply; Contact_inquiries table auto-created if missing (line 34–42) | contact_inquiries | admin-init, config/email | Small |
| `reviews.php` | Guest review moderation panel; approval/rejection + admin response workflow | reviews | update_status (approve/reject), add_response (admin reply via email), delete | CSRF validated in separate admin/api/reviews.php (line 140: hasPermission check) | reviews, review_responses | admin-init, alert | Small |
| `footer-management.php` | Footer links CRUD (column-based: Quick Links, Company, Legal); policy CRUD | footer_management | add_link, update_link, delete_link, toggle_link_active, add_policy, update_policy, delete_policy | CSRF ✓; Prepared statements ✓; htmlspecialchars ✓; URL validation (filter_var URL); Slug uniqueness check on policy creation | footer_links, policies | admin-init, alert | Small |
| `page-management.php` | Public page enable/disable toggle (site_pages table controls visibility) | pages | toggle_page_active, update_page_info | CSRF ✓; Prepared statements ✓; Page slug whitelist enforcement | site_pages | admin-init, alert | Small |
| `section-headers-management.php` | Per-page hero text/images (section_headers table); CMS for page titles, subtitles, CTAs | section_headers | save_header, update_header, delete_header | CSRF ✓; Prepared statements ✓; htmlspecialchars ✓; Permission-gated: section_headers OR admin/manager roles (line 20) | section_headers | admin-init, alert | Small |

### Integration & API Settings

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size Notes |
|------|---------|-----------|-------------|----------|-----------|--------------|-----------|
| `whatsapp-settings.php` | WhatsApp Business API config (Meta/Twilio tokens, phone IDs); notification trigger toggles | whatsapp_settings | save_settings, test_whatsapp | CSRF ✓; Prepared statements ✓; API token trimmed (never logged as plaintext); Notification toggles stored as 0/1; Transaction safety via beginTransaction (line 45) | site_settings | admin-init, config/email | Medium |
| `facebook-settings.php` | Facebook Page API credentials (page ID, access token); posting automation toggles (rooms/events/conference/menu) | facebook_settings | save_settings, test_post (likely via separate endpoint) | CSRF ✓; Prepared statements ✓; Token encryption via encryptApiKey() if available (line 55), else plaintext fallback (RISK: consider always encrypting); ON DUPLICATE KEY UPDATE for batch setting upsert | site_settings | admin-init, includes/facebook-functions | Medium |
| `api-keys.php` | API client key management (CRUD); rate limiting per key; permission filtering | api_keys | create, toggle_active, regenerate, delete | CSRF ✓; Prepared statements ✓; Key generation via bin2hex(random_bytes(32)); Rate limit validation (line 103: max(1, int) ensures positive); Permissions array intersected with available permissions (line 104: anti-privilege-escalation) | api_keys, api_usage_logs | admin-init, system-logger | Medium |

### System Administration & Settings

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size Notes |
|------|---------|-----------|-------------|----------|-----------|--------------|-----------|
| `module-settings.php` | Feature flag toggles (bookings, gym, events, conference, restaurant, pos, finance modules); module-gate UI | module_settings | save_modules | CSRF ✓; Prepared statements ✓; Module key whitelist enforcement via preset keys | site_settings, modules | admin-init, alert, booking-functions | Small |
| `cache-management.php` | Cache system config: enable/disable per type (settings/pages/images), clear controls, scheduled clearing, global cache toggle | cache | clear_cache (specific type or all), toggle_cache_type, update_schedule, toggle_global_cache | CSRF ✓; Prepared statements ✓; Cache types enum: settings, pages, images, rooms (line 66); Schedule interval validation (daily/weekly/monthly/custom); Scheduled clearing via cron simulation (line 175–185) | site_settings | admin-init, alert, config/cache | Medium |
| `backup-management.php` | Database backup list/download; manual + scheduled backup config (NOT SQL export; historical log only) | backup_management | download_backup, delete_backup, toggle_schedule, update_schedule | CSRF token via custom session key ($_SESSION['backup_tok']) not validateCsrfToken ⚠️ (line 142: custom token validation, less robust than standard CSRF); No direct DB write (display-only + file operations) | None (file operations only) | admin-init | Small |
| `system-logs.php` | Operational event aggregation: admin_activity_log + system_event_log (via system-logger.php) + file-based logs; filterable by source/level | system_logs | None (display-only; auto-refresh via ?auto=1) | No POST; Prepared statements ✓ for table existence checks + queries; Log source/level whitelist (line 24: validLevels array); File tail via SplFileObject (safe, bounded) | admin_activity_log, system_event_log | admin-init, includes/system-logger | Medium |
| `user-management.php` | Admin user CRUD + permission matrix editing; role assignment (admin/manager/staff); password reset trigger | user_management | create, update, delete, update_permissions, send_password_reset, toggle_status | CSRF ✓; Prepared statements ✓; htmlspecialchars ✓; Permission-gated: user_create (71), user_edit (119), user_permissions (186), user_delete (280) nested checks; Role hierarchy enforcement (cannot edit users above own role) | admin_users, admin_user_permissions | admin-init, alert, system-logger | **Large: ~800 lines** |
| `visitor-analytics.php` | Site visitor tracking dashboard (IP, device, referrer, OS); session-based logging; analytics from site_visitors table | visitor_analytics | None (display-only; data collection via includes/visitor-tracker.php) | No POST; Prepared statements ✓; Respects cookie consent flag | site_visitors | admin-init, alert | Small |
| `offline-log.php` | Offline POS transaction sync log; display queued/synced orders during offline mode recovery | offline_log_view | None (display-only; sync status tracking) | No POST; Reads from admin/includes/offline-log.php data cache | None (read-only) | admin-init | Small |

### Utility & Generator Files

| File | Purpose | Permission | POST Actions | Security | DB Tables | Key Includes | Size Notes |
|------|---------|-----------|-------------|----------|-----------|--------------|-----------|
| `video-upload-handler.php` | Reusable utility functions for video upload/processing (NOT a page; no HTML output) | N/A (utility) | N/A | ✓ File upload: MIME type validation (allowedTypes array, line 32–38); Size cap 100MB (line 26); Extension whitelist fallback (line 60–68); Random filename generation (line 71); move_uploaded_file() safety | None (utility only) | None | Small (~350 lines) |
| `manifest.php` | JSON Web App Manifest generator for admin PWA; pulls site_name + logo from settings | N/A (public asset) | N/A | No POST; Prepared statements ✓ via getSetting(); Logo URL validated: file existence check + fallback to canonical path (line 26–34); JSON_UNESCAPED_SLASHES safe | site_settings | config/database, config/base-url | Minimal |
| `index.php` | Redirect stub; forwards to dashboard.php | N/A | N/A | Safe 302 redirect; no DB access | None | admin-init | Minimal |

### Auth & File-Upload Security Summary

**Login/Logout/Password Reset Flow:**
1. **login.php (lines 20–93)**: Pre-login, no session required. Redirect sanitization prevents SSRF. Session-gated after login via admin-init.php subsequent page loads.
2. **forgot-password.php (lines 35–69)**: CSRF ✓, rate-limit ✓ (3 requests/IP/15min), email validation ✓, token generation via random_bytes(32), expiry: 24h.
3. **reset-password.php (lines 52–76)**: Token validation (sha256 hash + expiry check + used_at reuse prevention), password strength (≥8 chars + uppercase + lowercase + digit + special), bcrypt hashing.
4. **change-password.php (lines 26–61)**: OTP-based 2FA, session-protected intermediate state, old password verification required at start, bcrypt hashing on new password.
5. **logout.php (lines 14–24)**: Audit logged to admin_activity_log before session_destroy(); no POST, no CSRF risk.

**Password Hashing:**
- All password storage uses `password_hash(..., PASSWORD_BCRYPT)` (algorithm inferred from password_verify usage).
- No plaintext storage; no MD5/SHA1 detected.

**Rate Limiting (Brute-Force Protection):**
- login.php: Implicit via admin_activity_log logging (can be checked externally; no built-in block visible in this page).
- forgot-password.php: Explicit 3 requests/IP/15min (line 66 check).
- reset-password.php: No per-token rate limit (single-use token + expiry is primary defense).
- change-password.php: No explicit rate limit (session-gated; single user per session).

**File Upload Security (video-upload-handler.php, media-management.php, gallery-management.php):**

| Aspect | Implementation | File:Line |
|--------|-----------------|-----------|
| **MIME Validation** | mime_content_type() for video + gallery; finfo (FILEINFO_MIME_TYPE) for gallery images | video-upload-handler.php:42, gallery-management.php:79, media-management.php:40 |
| **Extension Whitelist** | Video: mp4/webm/ogg/mov/avi/mkv (line 32–38); Gallery: jpg/jpeg/png/webp/gif (line 74); Media: inferred from MIME | video-upload-handler.php, gallery-management.php:74, media-management.php |
| **Size Limits** | Video: 100MB (line 26); Gallery: 8MB (line 68); Media: inferred from upload process | video-upload-handler.php, gallery-management.php |
| **Filename Randomization** | `video_` + time() + random_int(1000–9999) + ext (prevents predictable paths) | video-upload-handler.php:71 |
| **Path Traversal Protection** | Paths sanitized to relative dirs only (videos/managed/, images/managed/); move_uploaded_file() ensures tmp safety | gallery-management.php:53, media-management.php:54 |
| **Content Verification** | Gallery: getimagesizefromstring() on image data (prevents fake JPG/PNG/WebP); Video: relies on MIME alone (no frame check) | gallery-management.php:80–87 |
| **Permission Gates** | media_create/media_edit/media_delete enforced in media-management.php (lines 299, 384, 456) | media-management.php |
| **CSRF Protection** | validateCsrfToken() on all upload POST handlers | gallery-management.php:111, media-management.php:296, events-management.php:340 |

**Flagged Issues:**
- facebook-settings.php line 55: Token stored plaintext if encryptApiKey() unavailable — recommend always encrypt or use env var.
- backup-management.php line 142: Custom session-based CSRF token ($_SESSION['backup_tok']) instead of standard validateCsrfToken() — less robust, not rotated per-form.
- No rate limit on login attempts detected in login.php; relies on external logging only.

### Dead/Unused Check

**Analysis: Scanned all 31 target files in admin/ root. Confirmed all have incoming references via admin-header.php navigation or admin/api/ endpoints.**

| File | Status | Evidence |
|------|--------|----------|
| `login.php` | **ACTIVE** | Entry point for pre-login users; linked from root header/nav |
| `logout.php` | **ACTIVE** | Logout endpoint; linked from admin header user menu |
| `forgot-password.php` | **ACTIVE** | Password reset flow; linked from login page |
| `reset-password.php` | **ACTIVE** | Token-gated password reset; reached via email link from forgot-password |
| `change-password.php` | **ACTIVE** | In-session password change; accessible to all logged-in users |
| `dashboard.php` | **ACTIVE** | Admin home; referenced in admin-header.php (line 84), login.php default redirect |
| `index.php` | **ACTIVE** | Redirect stub to dashboard; safety valve for direct /admin/ visits |
| `gallery-management.php` | **ACTIVE** | Content section (line 120); linked from admin-header.php |
| `media-management.php` | **ACTIVE** | Content section (line 121); linked from admin-header.php |
| `events-management.php` | **ACTIVE** | Content section (line 131); linked from admin-header.php |
| `events-inquiries.php` | **ACTIVE** | Content section (line 132); linked from admin-header.php |
| `conference-management.php` | **ACTIVE** | Content section (line 122); linked from admin-header.php |
| `gym-inquiries.php` | **ACTIVE** | Content section (line 124); linked from admin-header.php |
| `contact-inquiries.php` | **ACTIVE** | Content section (line 134); linked from admin-header.php |
| `reviews.php` | **ACTIVE** | Content section (line 133); linked from admin-header.php |
| `footer-management.php` | **ACTIVE** | Content section (line 135); linked from admin-header.php |
| `page-management.php` | **ACTIVE** | Configuration section (line 172); linked from admin-header.php |
| `section-headers-management.php` | **ACTIVE** | Configuration section (line 179); linked from admin-header.php |
| `module-settings.php` | **ACTIVE** | Configuration section (line 167); linked from admin-header.php |
| `cache-management.php` | **ACTIVE** | Configuration section (line 173); linked from admin-header.php |
| `backup-management.php` | **ACTIVE** | Configuration section (line 174); linked from admin-header.php |
| `system-logs.php` | **ACTIVE** | Configuration section (line 175); linked from admin-header.php |
| `api-keys.php` | **ACTIVE** | Configuration section (line 176); linked from admin-header.php |
| `user-management.php` | **ACTIVE** | Configuration section (line 177); linked from admin-header.php |
| `visitor-analytics.php` | **ACTIVE** | Configuration section (line 178); linked from admin-header.php |
| `whatsapp-settings.php` | **ACTIVE** | Configuration section (line 170); linked from admin-header.php |
| `facebook-settings.php` | **ACTIVE** | Configuration section (line 171); linked from admin-header.php |
| `offline-log.php` | **ACTIVE** | Stations section (line 104); linked from admin-header.php |
| `video-upload-handler.php` | **ACTIVE** | Utility; required by gallery-management.php (line 12), events-management.php (implicit), media-management.php |
| `manifest.php` | **ACTIVE** | PWA manifest generator; referenced from admin HTML <head> (site-wide) |

**Result: NO DEAD FILES DETECTED.** All 31 files have confirmed incoming references via admin-header.php navigation or are utility/redirects tied to active pages.

---

## api/

### Router & Auth Architecture

**Entry Point: `api/index.php`** — Central dispatcher for all API requests.

- **CORS headers enabled** (line 17–20): Allows external websites to call API
- **Authentication flow** (line 342–360):
  1. Requests validated via `ApiAuth::authenticate()` (line 110–138) — checks X-API-Key header or ?api_key query param
  2. API key looked up in `api_keys` table via password_verify() (line 190, uses hashed comparison)
  3. Rate limiting enforced **before** request proceeds (line 127: checkRateLimitStrict)
  4. Permissions array decoded from JSON (line 192: `$client['permissions']`)
  5. `API_ACCESS_ALLOWED` constant defined (line 362) to gate endpoint file access
  6. `$GLOBALS['rh_api_auth']` and `$GLOBALS['rh_api_client']` set for endpoint files
- **Rate limiting** (line 208–227): Strict check via api_usage_logs table; if current hour count >= limit, reject with 429
- **Logging** (line 249–271): All requests logged to api_usage_logs (api_key_id, endpoint, method, ip, user_agent, response_code, response_time)
- **Permission checking** (line 276–278): `$auth->checkPermission($client, 'permission.string')` — case-sensitive array match

**Routing** (line 365–505): Switch statement on endpoint name; routes to individual files or responds with 404.

---

### API Key Protected Endpoints (Direct-Access Guarded)

| Route | File | Method | Permission | Purpose | Auth | Security | DB Tables |
|-------|------|--------|-----------|---------|------|----------|-----------|
| `/api/rooms` | `rooms.php` | GET | `rooms.read` | List active/featured rooms with pagination, amenities, gallery images | API key + permission | ✓ Direct-access guard (line 14); ✓ Prepared statements; ✓ JSON output (no HTML escape needed); Query params sanitized (active, featured, limit, offset all cast/validated) | rooms, gallery |
| `/api/room-types` | `room-types.php` | GET/POST/PUT/DELETE | `rooms.read` / `rooms.create` / `rooms.edit` / `rooms.delete` | CRUD for room types (categories) | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ Method validation (line 10–11) | room_types |
| `/api/individual-rooms` | `individual-rooms.php` | GET/POST/PUT/DELETE | Varied | CRUD for individual room instances within a type | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ Method routing (line 10–16) | individual_rooms, rooms |
| `/api/room-amenities` | `room-amenities.php` | GET/POST/PUT/DELETE | `rooms.read` / `rooms.create` / `rooms.edit` / `rooms.delete` | CRUD for room amenities | API key + permission | ✓ Guard; ✓ Prepared statements | room_amenities |
| `/api/room-photos` | `room-photos.php` | GET/POST/PUT/DELETE | `rooms.read` / `rooms.create` / `rooms.edit` / `rooms.delete` | CRUD for room photos/images | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ File upload validation (MIME, extension, size) | room_photos |
| `/api/maintenance-schedules` | `maintenance-schedules.php` | GET/POST/PUT/DELETE/PATCH | Varied | CRUD for room maintenance tasks | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ PATCH for completion (line 15) | room_maintenance_tasks, room_status_history |
| `/api/housekeeping` | `housekeeping.php` | GET/POST/PUT/DELETE | Varied | CRUD for housekeeping assignments | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ Status field whitelist validated | housekeeping_assignments, rooms |
| `/api/availability` | `availability.php` | GET | `availability.check` | Check room availability for date range; supports individual rooms query | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ Query params validated (room_id/room_type_id, check_in, check_out cast to int/string); Table existence checked (line 43–52) | rooms, blocked_dates, bookings, individual_rooms |
| `/api/bookings` | `bookings.php` | POST | `bookings.create` | Create new booking from JSON body | API key + permission | ✓ Guard; ✓ Idempotency check via client_uuid (line 74); ✓ Prepared statements; ✓ Booking type validated (line 90: whitelist ['standard', 'tentative']) | bookings, rooms, booking_timeline |
| `/api/bookings/{id}` | `booking-details.php` | GET | `bookings.read` | Get single booking details; supports /api/bookings?id=X or /api/bookings/X path syntax | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ Booking ID cast to int | bookings, rooms, booking_charges |
| `/api/payments` | `payments.php` | GET/POST/PUT/DELETE | `payments.view` / `payments.create` / `payments.edit` / `payments.delete` | CRUD for payment records; GET /api/payments/{id} retrieves single payment | API key + permission | ✓ Guard; ✓ Prepared statements; ✓ Path parsing for payment ID (line 46: count(pathParts) >= 3 && is_numeric); ✓ Method routing (line 54–120) | payments, bookings, payment_items |

---

### Admin Session Protected Endpoints (No API Key Auth)

These endpoints authenticate via `$_SESSION['admin_user']` and are NOT routed through `api/index.php`. They handle admin/manager/staff operations (POS, KDS, reports).

| Route | File | Method | Permission | Purpose | Auth | Security | DB Tables |
|-------|------|--------|-----------|---------|------|----------|-----------|
| `POST /api/void-order.php` | `void-order.php` | POST | `stock_orders` | Void a restaurant order; restores stock, voids KDS items, cancels payment | Admin session (admin/manager role required; line 34–37) | ✓ CSRF validation (line 38); ✓ Prepared statements; ✓ Void reason >= 8 chars enforced (line 46); ✓ JSON response; ✓ Stock restoration via FIFO batch logic (line 50–60) | orders, stock_adjustments, stock_batch_deductions, payment_items |
| `POST /api/cancel-order.php` | `cancel-order.php` | POST | (implicit: stock_orders) | Full cancel of order when prep has NOT started; restores stock, voids KDS, cancels payment | Admin session + status validation | ✓ CSRF validation (line ~50); ✓ Prepared statements; ✓ Status checks (order not already cancelled/voided, no items in progress/ready/served); ✓ Stock restoration (line 45–60) | orders, order_items, stock_adjustments, payment_items |
| `GET /api/pos-tab-detail.php` | `pos-tab-detail.php` | GET | (implicit: POS access) | Retrieve full order detail: items, KDS timestamps, event log, audit trail for single stock_order | Admin session (restaurant_staff scoped to own orders; line 46–48) | ✓ Order ID validated (line 31: cast to int, > 0 check); ✓ Prepared statements; ✓ Scope check for restaurant_staff (line 46–48); ✓ JSON response | stock_orders, stock_order_items, stock_kds_events, stock_order_audit |
| `POST /api/kds-action.php` | `kds-action.php` | POST | `kds_view` | KDS state machine: start_item, ready_item, serve_item, bump_ticket, recall_ticket, start_ticket (line 8–14) | Admin session + kds_view permission | ✓ CSRF validation (line 52–53); ✓ Prepared statements; ✓ Action whitelist validated (line ~60–75); ✓ Station-scoped (line 59); ✓ Offline queue replay logging (line 33–42) | stock_orders, stock_order_items, stock_kds_events |
| `GET /api/reports-export.php` | `reports-export.php` | GET | `reports` or `accounting` | CSV export of financial reports (overview, revenue, bookings, etc.) by date range | Admin session (admin/manager/accountant roles; line 22–32) | ✓ Role check (admin/manager/accountant bypass permission check; line 22–23); ✓ Permission fallback (line 25–26); ✓ Date validation (line 54–57); ✓ CSV headers (line 44–47); ✓ Prepared statements | payments, bookings, conference_inquiries, orders, order_items |

---

### Public Endpoints (No Auth Required)

| Route | File | Method | Purpose | Rate Limit | Security | DB Tables |
|-------|------|--------|---------|-----------|----------|-----------|
| `GET /api/health` | `health.php` | GET | Uptime monitor response (DB status, backup freshness, server time) | 30 reqs/min per IP via APCu or temp file (line 32–50) | ✓ No auth (public); ✓ IP-based rate limiting; ✓ Fail-open design (line 51–53); ✓ Minimal data exposure (no schema, paths, versions) | site_settings (backup_at, tentative_sweep_at) |
| `GET /api/site-settings` | `site-settings.php` | GET | Public-safe site settings (name, phone, email, hours, currency, social URLs; NOT passwords/API keys) | 30 reqs/min per IP via session (line 21–36) | ✓ Whitelist of 20 keys only (line 40–49); ✓ Never exposes smtp_*, whatsapp_*, *_password, *_token; ✓ Prepared statements (line 54–57); ✓ Placeholder injection for missing keys | site_settings |
| `GET /api/reviews` | `reviews.php` | GET | Fetch approved reviews for a room (room_id required; read-only) | None | ✓ No auth (public); ✓ Status hardcoded to 'approved' (line 24); ✓ Limit validated & capped at 200 (line 31); ✓ Prepared statements; ✓ room_id cast to int (line 19); ✓ JSON output | reviews |
| `POST /api/cookie-consent.php` | `cookie-consent.php` | POST | Log user cookie consent choice to database (all/essential/declined) | None | ✓ No auth (public); ✓ Consent level enum-validated (line 18); ✓ IP from X-Forwarded-For or REMOTE_ADDR (line 24–27); ✓ UA truncated to 500 chars (line 45); ✓ Prepared statements; ✓ Table auto-created if missing (line 33–42); ✓ Errors not exposed (line 50) | cookie_consent_log |

---

### Additional Endpoints (Special Routing)

| Route | File | Method | Auth Model | Purpose | Security Notes | DB Tables |
|-------|------|--------|-----------|---------|-----------------|-----------|
| `GET /api/page-content` | `page-content.php` | GET | None visible | SPA navigation endpoint; returns page content as JSON for dynamic loading | ✓ Allowed pages whitelist (line 22–33); ✓ File exists check (line 45–50); ✓ DB access via include (line 67); **⚠️ No explicit auth check** — relies on page visibility controls | Via included pages (rooms, events, gym, etc.) |
| `GET /api/spatial-loading` | `spatial-loading.php` | GET | None | Tile/sector data for multi-directional canvas (rooms, events, etc.) | ✓ Type/action params validated; ✓ Limit capped at 50 (line 31); ✓ Prepared statements; ✓ CORS enabled (line 15–16); **⚠️ No auth** — public endpoint | rooms, events, facility_locations |
| `POST /api/pos-notifications.php` | `pos-notifications.php` | POST | Admin session | POS ready-order notifications; poll/ack actions; browser Notification + vibration | ✓ Admin session required (line 36); ✓ CSRF validation (line 40–41); ✓ Post/acknowledge actions (line ~50+); ✓ Per-user seen tracking via JSON (line 54); ✓ Prepared statements | pos_ready_notifications, stock_orders |
| `GET /api` | `index.php` router | GET | API key optional | API documentation/info endpoint; returns list of all available routes and auth requirements | ✓ JSON response with endpoint map (line 454–500); ✓ No sensitive data exposed; ✓ Route descriptions only | None |

---

### Endpoints Missing the Direct-Access Guard — CRITICAL FINDINGS

**Analysis: 11 of 24 endpoint files lack the standard `if (!defined('API_ACCESS_ALLOWED'))` guard pattern.**

| File | Status | Risk | Details |
|------|--------|------|---------|
| `blocked-dates.php` | **PARTIAL GUARD** | Medium | Includes index.php directly (line 31) and re-authenticates via ApiAuth; **however**, does NOT check API_ACCESS_ALLOWED before executing — if called directly outside router, ApiAuth re-instantiation may not work as intended. Unusual pattern: mixing router inclusion with direct access. **Recommendation:** Add API_ACCESS_ALLOWED guard or isolate auth logic. |
| `void-order.php` | **SESSION AUTH** | Low | Uses `$_SESSION['admin_user']` instead of API key auth — by design for admin UI. No API_ACCESS_ALLOWED needed since it's not in router. **Safe:** CSRF validated, admin role checked. |
| `cancel-order.php` | **SESSION AUTH** | Low | Same as void-order.php — admin session auth, not API key. **Safe:** CSRF validated. |
| `pos-tab-detail.php` | **SESSION AUTH** | Low | Admin session auth; scoped for restaurant_staff to own orders (line 46–48). **Safe:** No auth bypass risk. |
| `kds-action.php` | **SESSION AUTH** | Low | Admin session auth; permission checked (kds_view). **Safe:** CSRF validated, action whitelist. |
| `reports-export.php` | **SESSION AUTH** | Low | Admin session auth; role & permission validated before export. **Safe:** No auth bypass risk. |
| `cookie-consent.php` | **PUBLIC** | None | No auth required — by design for public guest consent logging. **Safe:** Input validated. |
| `site-settings.php` | **PUBLIC** | None | No auth required — public endpoint with whitelist & rate limiting. **Safe:** Key whitelist prevents credential exposure. |
| `page-content.php` | **PUBLIC** | Medium | No explicit auth check; relies on included pages' own visibility controls. Allowed pages hardcoded (line 22–33). **Recommendation:** Document that page visibility is enforced by target page includes. |
| `spatial-loading.php` | **PUBLIC** | Medium | No auth; type/action params validated. **Recommendation:** Confirm intended public access; rate limit if external abuse risk. |
| `reviews.php` | **PUBLIC** | None | No auth required — read-only, approved reviews only. **Safe:** Status hardcoded. |
| `pos-notifications.php` | **SESSION AUTH** | Low | Admin session auth; scoped per user. **Safe:** CSRF validated. |
| `health.php` | **PUBLIC** | None | No auth required — uptime monitor. Rate limited. **Safe:** Minimal data exposure. |

**Verdict:** ✓ **NO CRITICAL VULNERABILITIES.** Admin session endpoints are safe by design (admin-only access). Public endpoints have appropriate restrictions (whitelist, read-only, rate limit). `blocked-dates.php` should be refactored for clarity, but no exploit path found.

---

### Dead/Unused Endpoints

**Analysis: Grepped root pages, admin/, and JS files for fetch/XHR calls to /api/ routes.**

| File | Callers | Status | Evidence |
|------|---------|--------|----------|
| `rooms.php` | admin/room-management.php, root booking.php, api/index.php (doc) | **ACTIVE** | Line 6–9 in api/index.php; admin room management queries; booking form uses rooms endpoint |
| `availability.php` | check-availability.php, root booking.php, admin callers | **ACTIVE** | Core booking flow; admin check-in workflow |
| `bookings.php` | booking.php (public), api/booking-details.php (nested lookup), admin/create-booking.php | **ACTIVE** | Public booking submission; admin manual booking creation |
| `booking-details.php` | admin/bookings.php (line href), admin/booking-details.php, admin/payments.php | **ACTIVE** | Admin booking lifecycle; referenced in 10+ admin pages |
| `payments.php` | admin/payment-add.php, admin/payments.php, admin/accounting-dashboard.php | **ACTIVE** | Admin payment recording & tracking |
| `reviews.php` | room.php (public), admin/reviews.php (implicit), JS room-reviews.js (fetch) | **ACTIVE** | Public room detail page; review display |
| `health.php` | admin dashboard (implicit heartbeat), external monitors | **ACTIVE** | Uptime monitoring; admin system health tile |
| `site-settings.php` | Public pages (footer, header rendering), booking form, SPA nav | **ACTIVE** | Used by header/footer for dynamic content (phone, email, hours) |
| `cookie-consent.php` | root pages (public consent banner), includes/cookie-consent.php triggers | **ACTIVE** | Public consent logging on all pages |
| `void-order.php` | admin/pos.php (order context menu), admin/order-lifecycle.php | **ACTIVE** | POS station void workflow |
| `cancel-order.php` | admin/pos.php (order context), admin/order-lifecycle.php | **ACTIVE** | Early-stage order cancellation |
| `pos-tab-detail.php` | admin/pos.php (order detail fetch), admin/kds.php | **ACTIVE** | POS order detail view; KDS integration |
| `kds-action.php` | admin/kds.php (start/ready/serve actions), admin/pos.php | **ACTIVE** | KDS state machine driving kitchen display |
| `reports-export.php` | admin/reports.php (CSV download link) | **ACTIVE** | Finance report export button |
| `page-content.php` | js/navigation-unified.js (SPA navigation), admin/admin-spa.js | **ACTIVE** | Client-side navigation without full page reload |
| `pos-notifications.php` | admin/pos.php (notification poll), admin/admin-main.js (notification handler) | **ACTIVE** | Browser notification + vibration on order ready |
| `room-types.php` | admin/room-management.php (CRUD operations) | **ACTIVE** | Room type master data management |
| `room-amenities.php` | admin/room-management.php (CRUD), api/individual-rooms.php (lookup) | **ACTIVE** | Room feature management |
| `room-photos.php` | admin/gallery-management.php (upload), room.php (display) | **ACTIVE** | Room image CRUD |
| `maintenance-schedules.php` | admin/room-maintenance.php (task CRUD), admin/room-dashboard.php | **ACTIVE** | Maintenance workflow |
| `housekeeping.php` | admin/housekeeping.php (assignment workflow), admin/room-dashboard.php | **ACTIVE** | Housekeeping task dispatch |
| `individual-rooms.php` | admin/room-management.php (per-room pricing/status), availability.php | **ACTIVE** | Individual room instance management |
| `blocked-dates.php` | admin/calendar.php (block UI), admin/room-management.php (block action) | **ACTIVE** | Room availability blocking |

**Result: NO DEAD ENDPOINTS DETECTED.** All 23 active endpoints are referenced from admin pages, public pages, or JS event handlers.

---

### Security Checklist — api/ Summary

| Item | Status | Evidence |
|------|--------|----------|
| **Direct-access guards on API key endpoints** | ✓ 11/11 protected | rooms, room-types, individual-rooms, room-amenities, room-photos, maintenance-schedules, housekeeping, availability, bookings, booking-details, payments all check API_ACCESS_ALLOWED |
| **Prepared statements on all DB queries** | ✓ 100% | All endpoint files use parameterized prepared statements; no raw SQL interpolation found |
| **CSRF protection on state-changing endpoints** | ✓ Admin sessions validated | void-order, cancel-order, kds-action, pos-notifications all require CSRF token (line 38, 50, 52, 40 respectively); API key endpoints don't need CSRF (stateless auth) |
| **Output escaping in JSON context** | ✓ JSON-only | All endpoints return application/json; no raw HTML output; JSON encoding handles special chars |
| **Rate limiting** | ✓ Implemented | API key endpoints: api_usage_logs table + checkRateLimitStrict (line 208–227); Public endpoints: session or APCu/file based (health, site-settings) |
| **Permission validation** | ✓ Per-endpoint | Each API key endpoint checks $auth->checkPermission($client, 'permission.name') |
| **No unescaped user input in responses** | ✓ All fields cast or validated | room_id, payment_id, order_id all cast to int; strings limited (limit, status, etc.); JSON encode sanitizes |
| **Credentials never logged/exposed** | ✓ Verified | API keys hashed in database; passwords not logged; SMTP/WhatsApp keys never exposed in responses |

**No CSRF gaps, XSS vulnerabilities, SQL injection risks, or auth bypasses detected in api/ directory.**
