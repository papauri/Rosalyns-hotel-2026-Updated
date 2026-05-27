# Booking Email and PDF Template System

This document is the current reference for template editing, previewing, test sending, defaults, and full revert behavior.

## Purpose

The booking template system in admin now handles all of the following in one place:

1. Edit email and PDF template content for all booking-related template keys.
2. Preview rendered template output with realistic sample data.
3. Send live test emails (or test PDF attachments for document templates).
4. Reset one template or all templates back to the canonical built-in design.

## Primary Admin Surface

- `admin/booking-settings.php`
  - Main editor UI for all booking email and PDF templates.
  - Per-template `Load Default` action.
  - Full `Reset All to Defaults` action.
  - AJAX preview endpoint (`booking_email_template_preview` + `_ajax_preview`).
  - AJAX test-send endpoint (`send_test_email` + `_ajax_send_test`).

## Canonical Default Source

Canonical defaults are defined in:

- `config/email.php`

Key functions:

- `ensureBookingEmailTemplateDefaults()`
  - Seeds missing template rows in `booking_email_templates`.
- `hotel_booking_template_defaults_map()`
  - Exposes the canonical defaults map used by runtime seeding and admin revert actions.
- `resetBookingEmailTemplatesToDefaults(bool $preserveActivationState = true)`
  - Force-resets all template keys to built-in subject + HTML defaults.
  - Preserves each row's `is_active` state when requested.

## Persistence Layer

Templates are stored in the database table:

- `booking_email_templates`

Relevant helpers in `config/database.php`:

- `ensureBookingEmailTemplatesTable(...)`
- `getBookingEmailTemplateConfig(...)`
- `upsertBookingEmailTemplateConfig(...)`

## Supported Template Keys

Current booking template keys:

- `booking_received`
- `booking_confirmed`
- `booking_cancelled`
- `payment_invoice`
- `payment_invoice_document`
- `conference_invoice`
- `conference_invoice_document`
- `tentative_booking_created`
- `tentative_booking_reminder`
- `tentative_booking_expired`
- `tentative_booking_converted`
- `tentative_quotation`
- `tentative_quotation_document`
- `conference_quotation`
- `conference_quotation_document`
- `event_quotation`
- `event_quotation_document`
- `credit_note`
- `credit_note_document`
- `payment_receipt`
- `payment_receipt_document`

Document/PDF template keys (rendered as PDF attachments in test-send flow):

- `payment_invoice_document`
- `conference_invoice_document`
- `tentative_quotation_document`
- `conference_quotation_document`
- `event_quotation_document`
- `credit_note_document`
- `payment_receipt_document`

## Preview Behavior

### Email templates

- Preview resolves placeholders against sample data.
- The rendered body is wrapped with the shared email shell (`wrapEmailTemplate(...)`) for realistic visual output.

### PDF document templates

- Preview resolves placeholders against sample data.
- The preview body is rendered directly as document HTML and marked as `is_document = true`.
- Test send for document templates generates a real PDF attachment using `bookingRenderPdfFromHtml(...)` and sends via `sendEmailWithAttachments(...)`.

## Save and Revert Actions

### Save all templates

- POST key: `booking_email_templates`
- Writes each template's subject/html/text and activation state through `upsertBookingEmailTemplateConfig(...)`.

### Per-template reset

- UI: `Load Default` button on each template tab.
- Source: `hotel_booking_template_defaults_map()`
- Scope: current tab only (subject + HTML in editor).
- Note: requires `Save All Templates` to persist to DB.

### Full reset for all templates

- POST key: `reset_all_booking_templates_to_defaults`
- Server action: `resetBookingEmailTemplatesToDefaults(true)`
- Scope: all template keys (subject + HTML).
- Activation behavior: existing `is_active` values are preserved.

## Security and Validation Notes

- CSRF validation is enforced for state-changing POST requests.
- AJAX preview/send endpoints return JSON and exit early.
- Empty subject or HTML is rejected during full save.

## Operational Validation Checklist

After any template-system change, validate in this order:

1. `php -l config/email.php`
2. `php -l admin/booking-settings.php`
3. `php -l docs/guides/12-email-templates.php`
4. Run `get_errors` on changed files and resolve diagnostics.
5. Browser check in admin on `booking-settings.php`:
   - Open Email Templates section.
   - Confirm per-template `Load Default` updates editor content.
   - Confirm `Reset All to Defaults` succeeds and reports success.
   - Run at least one email preview and one PDF-template test preview.

## Guardrails

- Do not hardcode SMTP credentials in source files.
- Keep template baseline definitions in `config/email.php` only.
- Avoid introducing a second hardcoded defaults map in admin files.
- Keep docs and template key lists synchronized when keys are added or removed.
