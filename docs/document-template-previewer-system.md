# Document Template Previewer System

This file is the saved reference for the full document previewer system so it can be reapplied if the working files are replaced, reformatted, or rebuilt.

## Purpose

The previewer does two jobs:

1. It shows the actual document output for invoice, receipt, room quotation, and conference quotation templates.
2. It provides a layout studio that edits the raw template HTML stored in `booking_email_templates` and saves those changes back to the database.

## Other Saveable Work From Today

These related pieces were also part of today's document work and are worth preserving alongside the previewer itself:

- Shared document standardization now centers on `includes/document-template-defaults.php`
  - invoice, receipt, room quotation, and conference quotation all draw from the same raw-template pattern
  - the current direction is minimalist, hotel-oriented, compact, and standardized across document types

- Database-backed document content is part of the system design
  - document HTML should come from `booking_email_templates` when present
  - database-backed values should win over hardcoded values when settings already exist

- Preview correctness depends on the real production render path
  - matching browser HTML alone is not enough
  - the preview must use the same TCPDF output path as the emailed attachments

- Template refresh workflow is part of the operating model
  - after changing shared defaults, refresh the live template rows with `php scripts/apply-document-template-refresh.php`

- Live document test workflow is also part of the saved system
  - room/invoice document tests can be sent with `php scripts/send-document-tests.php --email=<target> --booking-id=85`
  - conference quotation testing may require a synthetic payload when `conference_inquiries` has no suitable record

## Primary Files

### Runtime entry points

- `preview-document-templates.php`
  - Standalone/public preview route.
  - Loads real template HTML from DB or shared defaults.
  - Builds real HTML and real TCPDF output.
  - Hosts the layout studio save endpoint.
  - Supports embedded mode for reuse inside admin.

- `admin/document-template-previewer.php`
  - Admin-shell wrapper around the same previewer.
  - Defines embedded mode constants before including the standalone preview file.
  - Renders inside the admin SPA-compatible shell.

### Client assets

- `js/preview-document-templates.js`
  - Layout studio logic.
  - Section order controls.
  - Left/right row nudging.
  - Logo/reference drag handles.
  - Save action back to the preview route.

- `css/preview-document-templates-editor.css`
  - Layout studio styling.
  - Section cards.
  - Fixed-height design stage.
  - Overlay handles and guide lines.

### Shared integration point

- `admin/includes/admin-header.php`
  - Adds `document-template-previewer.php` under `Configuration`.

## Data and Rendering Dependencies

The previewer is not a fake sample page. It depends on the same surfaces used by live document output:

- `includes/document-template-defaults.php`
  - Shared raw template HTML defaults.

- `config/database.php`
  - `getBookingEmailTemplateConfig(...)`
  - `upsertBookingEmailTemplateConfig(...)`

- `config/invoice.php`
  - `buildInvoiceHTML(...)`

- `config/receipts.php`
  - `receipt_hydrate_context(...)`
  - `receipt_placeholders(...)`

- `includes/quotation-pdf.php`
  - `generateQuotationPDF(...)`
  - `generateConferenceQuotationPDF(...)`

- `config/email.php`
  - Shared document helper functions such as logo, bank details, address, terms, contact details.

## Supported Template Keys

The previewer currently supports these keys:

- `payment_invoice`
- `payment_invoice_document`
- `payment_receipt_document`
- `quotation_document`
- `conference_quotation`
- `tentative_quotation`

The layout studio is intended for the PDF-capable keys:

- `payment_invoice_document`
- `payment_receipt_document`
- `quotation_document`
- `conference_quotation`

## Route Modes

### Standalone route

Path:

- `preview-document-templates.php?template=payment_invoice_document`

Use this when the previewer should run outside admin.

### Admin route

Path:

- `admin/document-template-previewer.php?template=payment_invoice_document`

Use this when the previewer must live inside the admin header/sidebar layout and participate in admin navigation.

## Embedded Admin Mode

The standalone preview file supports reuse through three constants.

These are defined by `admin/document-template-previewer.php` before including the standalone file:

- `HOTEL_DOCUMENT_PREVIEW_EMBED`
  - When `true`, the standalone preview file does not emit its own full page shell.

- `HOTEL_DOCUMENT_PREVIEW_ASSET_PREFIX`
  - Used so CSS/JS asset paths resolve correctly from admin.
  - Current admin value: `../`

- `HOTEL_DOCUMENT_PREVIEW_ROUTE`
  - Used for save and PDF preview URLs.
  - Current admin value: `document-template-previewer.php`

## Server-Side Preview Flow

### Template load order

`preview-document-templates.php` resolves raw template HTML in this order:

1. `getBookingEmailTemplateConfig($currentKey, [])`
2. direct DB read from `booking_email_templates`
3. `hotel_document_template_default($currentKey)`

### Placeholder preview data

The preview route builds realistic sample data using:

- room data from `rooms`
- conference room data from `conference_rooms`
- latest booking/payment/inquiry data when available
- site settings for bank, contact, VAT, address, etc.

### Browser-safe HTML rendering

`$makePreviewHtmlBrowserSafe(...)` rewrites local Windows image paths into site URLs so the browser stage can display images that originally came from local file-style sources.

### Actual PDF rendering

The preview route builds real PDF output with TCPDF.

Key pieces:

- `$buildPdfPreviewBinary`
- `?format=pdf`
- embedded base64 PDF passed into the page
- PDF.js module script renders the actual PDF into canvases

This is why the preview matches the email attachment path more closely than the old HTML-only preview.

## Save Endpoint

The preview route accepts a POST save operation directly.

Trigger:

- `save_template_layout=1`

Validation rules:

1. localhost-only request source
2. session CSRF token match
3. valid `template_key`
4. non-empty `html_body`
5. template storage helpers available

Save action:

- preserves existing template name, subject, text body, and activation state
- updates only the raw `html_body`
- writes through `upsertBookingEmailTemplateConfig(...)`

Response format:

```json
{ "success": true, "message": "Template layout saved successfully.", "template_key": "payment_invoice_document" }
```

## Layout Studio Data Contract

The preview page injects JSON into:

- `#preview-template-designer-data`

Current payload fields:

- `templateKey`
- `templateName`
- `rawTemplateHtml`
- `sampleMap`
- `siteBaseUrl`
- `saveUrl`
- `pdfPreviewUrl`
- `csrfToken`
- `isPdfCapable`
- `canSave`

The JS file expects this payload to exist for the designer to initialize.

## Client Layout Studio Logic

### Section model

The JS parses the raw template HTML into a DOM model and treats the first outer table rows as reorderable sections.

Key helpers:

- `parseTemplate(...)`
- `getOuterRows(...)`
- `buildDescriptors(...)`

Current section labels are inferred from row content, for example:

- `Header`
- `Details Band`
- `Primary Content`
- `Payment History`
- `Bank Details`
- `Terms`

Header remains locked.

### Stage rendering

The stage renders placeholder-applied HTML into:

- `#preview-designer-stage`

Handles render into:

- `#preview-designer-handles`

Guide lines render inside:

- `#preview-designer-stage-shell`

### Section movement

The current system supports:

- drag-and-drop row reordering
- explicit up/down buttons for every movable section
- explicit left/right nudges for every movable section

Horizontal movement is implemented by adjusting the first cell's padding:

- left shift increases `padding-left` and decreases `padding-right`
- right shift does the opposite

This is persisted into the raw template HTML.

### Header movement

The current system supports two header handles:

- `Logo Y`
- `Reference Y`

Important implementation detail:

- Logo movement targets the logo wrapper `div` using `margin-top`
- Reference movement must target the inner reference card `div` using `margin-top`

Do not move the reference by changing the header cell `padding-top`. That distorts and crops the entire header band.

### Reset and save

- `Reset Draft` restores the last saved raw HTML.
- `Save Template Layout` posts back to the preview route, then reloads the page so the PDF canvas re-renders from the saved template.

## Critical Guardrails

These are the issues already found and fixed. If the system is reapplied, preserve these rules.

### 1. Do not rerender the entire stage on every image load

Bad behavior:

- the stage keeps rebuilding itself
- the preview looks like it is constantly loading

Required rule:

- image `load` events should only refresh the overlay handles
- they must not rerender the entire stage HTML

### 2. Keep the stage shell height fixed

Bad behavior:

- dragging header elements changes the container height
- the whole editing area appears to shrink or jump

Required rule:

- `.preview-designer__stage-shell` uses fixed `height: clamp(...)`
- not only `min-height`

### 3. Normalize old reference padding edits

The JS currently includes normalization logic that converts old reference cell `padding-top` edits into inner card `margin-top` edits.

Keep this normalization logic if the system is recreated, otherwise older saved templates can still render broken headers.

### 4. Save only raw template HTML

The editor must persist raw HTML with placeholders intact.

Do not save the rendered browser HTML with placeholder replacements already applied.

## Admin Integration Rules

To reapply the admin version, preserve all of the following:

1. `admin/document-template-previewer.php` must define the three embed constants before including `../preview-document-templates.php`
2. The page must render inside `#rh-admin-page`
3. The page must include the same previewer CSS and JS assets with admin-safe relative paths
4. `admin/includes/admin-header.php` must include `document-template-previewer.php` in the `Configuration` group

## Reapply Checklist

If the previewer has to be reapplied to new files, follow this exact order:

1. Restore or recreate `preview-document-templates.php`
2. Restore or recreate `js/preview-document-templates.js`
3. Restore or recreate `css/preview-document-templates-editor.css`
4. Restore or recreate `admin/document-template-previewer.php`
5. Re-add the `Document Previewer` menu entry in `admin/includes/admin-header.php`
6. Confirm the preview route can load raw template HTML from DB/defaults
7. Confirm `?format=pdf` returns real PDF output
8. Confirm PDF.js canvas preview renders on the page
9. Confirm section reorder buttons and left/right nudges work
10. Confirm logo/reference handle movement works without collapsing the header
11. Confirm save POST writes back to `booking_email_templates`
12. Reload and confirm the saved layout affects the PDF preview

## Validation Commands

After reapplying or repairing the system, run these checks:

```powershell
php -l preview-document-templates.php
php -l admin/document-template-previewer.php
php -l admin/includes/admin-header.php
node --check js/preview-document-templates.js
```

Then run diagnostics:

```text
get_errors on touched files
get_errors on the full workspace
```

## Current Menu Placement

The previewer is currently placed in admin under:

- `Configuration` -> `Document Previewer`

## Current Save Restrictions

Layout saving is currently restricted to localhost addresses:

- `127.0.0.1`
- `::1`
- `::ffff:127.0.0.1`

## If This Has To Be Rebuilt Quickly

Minimum file set to recover first:

- `preview-document-templates.php`
- `js/preview-document-templates.js`
- `css/preview-document-templates-editor.css`
- `admin/document-template-previewer.php`

Then re-add the admin menu item and rerun the validation sequence above.
