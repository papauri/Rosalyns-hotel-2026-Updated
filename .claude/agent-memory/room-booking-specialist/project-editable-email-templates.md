---
name: project-editable-email-templates
description: The four sync points required to add a new admin-editable booking email template (DB-backed, premium-shell UI)
metadata:
  type: project
---

Adding a new admin-editable booking/finance email template requires keeping FOUR places in sync; miss one and the email either isn't editable, isn't seeded, or previews with raw `{{placeholders}}`:

1. `config/email.php` → `ensureBookingEmailTemplateDefaults()` `$defaults` array — the canonical default, built with `hotel_premium_email_html()` + `hotel_premium_email_body()` + `hotel_premium_email_summary_rows()` for the shared Japandi-premium UI. Auto-upserts to DB on every include (only if not already present). Also exposed via `hotel_booking_template_defaults_map()` for the admin "Load Default" / reset buttons.
2. The send function — render via `renderBookingEmailTemplate($key, $vars)` with vars from `buildBookingEmailVariables($booking, $room, $extra)` (gives logo/site/address/contact/currency shell vars). Always keep a premium-wrapped code fallback for when the DB template is inactive/missing.
3. `admin/booking-settings.php` → add the key to `$booking_template_defs_master` AND `$booking_template_short_names` so it appears in the editor.
4. `admin/booking-settings.php` → add sample values for any custom placeholders to the `$ajaxVars` preview map (~line 247+), else preview shows raw `{{tags}}`.

No DB migration needed — the `booking_email_templates` table already exists and `ensureBookingEmailTemplateDefaults()` seeds new keys live. To force immediate seeding, just include `config/email.php` once.

Example: `refund_notification` (refund confirmation email) follows exactly this pattern; sent by `sendRefundNotificationEmail()` from `admin/payment-refund.php` after a refund commits. Status values used elsewhere: see [[project-booking-status-conventions]].
