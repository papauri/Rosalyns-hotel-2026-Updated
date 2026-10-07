<?php
/**
 * docs/guides/12-email-templates.php
 * Staff reference: how to edit email/PDF templates and which {{tags}} each template can use.
 * Search with ?q=
 */
declare(strict_types=1);

$siteName = 'Rosalyns Beach Hotel';
try {
    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../config/cache.php';
    $siteName = (string)(getSetting('site_name') ?: $siteName);
} catch (Throwable $e) {
    // Keep the fallback name.
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$q = trim(strip_tags((string)($_GET['q'] ?? '')));
$qLow = strtolower($q);

/* tag => what it fills in. Verified against the code that builds each message. */
$d = [
    'site_name' => 'The hotel name.',
    'site_url' => 'The hotel website address (Hotel Settings, Website address).',
    'contact_email' => 'The hotel sending email address.',
    'contact_phone' => 'The hotel main phone number.',
    'phone_main' => 'The hotel main phone number.',
    'currency_symbol' => 'The currency, for example MWK.',
    'payment_policy' => 'The payment policy wording from Hotel Settings.',
    'check_in_time' => 'Standard check-in time.',
    'check_out_time' => 'Standard check-out time.',
    'booking_reference' => 'The booking reference.',
    'guest_name' => 'The guest name.',
    'guest_email' => 'The guest email address.',
    'guest_phone' => 'The guest phone number.',
    'room_name' => 'Room type, with the assigned room number added when there is one.',
    'room_assignment' => 'The assigned room number(s).',
    'room_numbers' => 'Same as room_assignment.',
    'occupancy_type' => 'Occupancy label, for example Double.',
    'check_in_date_formatted' => 'Check-in date, like June 1, 2026.',
    'check_out_date_formatted' => 'Check-out date, like June 3, 2026.',
    'number_of_nights' => 'Number of nights.',
    'number_of_guests' => 'Total guests.',
    'adult_guests' => 'Number of adults.',
    'child_guests' => 'Number of children.',
    'tentative_expires_at_formatted' => 'When a tentative hold runs out.',
    'tentative_status' => 'Status of the booking in words.',
    'child_price_multiplier' => 'Child price as a percentage of the adult rate.',
    'child_supplement_total_formatted' => 'Child supplement total, as a number.',
    'total_amount_formatted' => 'Amount owed as a plain number, without the currency symbol.',
    'special_requests' => 'The guest special requests.',
    'cancellation_reason' => 'The reason given for a cancellation.',
    'rate_plan_label' => 'Name of the rate plan, if any.',
    'rate_plan_discount_formatted' => 'Rate plan discount for the stay, as a number.',
    'package_total_formatted' => 'Extras and packages total, as a number.',
    'packages_html' => 'A ready-made list of the packages and extras on the booking.',
    'vat_number' => 'The hotel VAT registration number.',
    'vat_number_html' => 'A line "VAT Reg. No.: ..." (empty when no number is set).',
    'vat_rate' => 'The VAT rate.',
    'vat_amount' => 'The VAT amount, or a dash when none.',
    'levy_rate' => 'Tourism levy rate.',
    'levy_amount' => 'Tourism levy amount, or a dash when none.',
    'subtotal_amount' => 'Amount before VAT.',
    'total_amount' => 'The total, with the currency symbol.',
    'logo_html' => 'The hotel logo as a picture.',
    'address' => 'The hotel address.',
    'days_overdue' => 'Days the account is overdue.',
    'urgency_notice' => 'A ready-made sentence about how overdue the balance is.',
    'tentative_duration_hours' => 'Length of the tentative hold in hours.',
    'hours_until_expiry' => 'Hours left before the hold ends.',
    'whatsapp_link' => 'A link that opens a WhatsApp chat with the hotel.',
    'conversion_status' => 'The word "converted".',
    'quotation_reference' => 'The quotation reference.',
    'quote_reference' => 'The quotation reference (same value).',
    'check_in_date' => 'Check-in date with the weekday, like Monday, June 1, 2026.',
    'check_out_date' => 'Check-out date with the weekday.',
    'nights' => 'Number of nights.',
    'guests' => 'Guest count in words, like 2 adults.',
    'rate_per_night' => 'Nightly rate with the currency symbol.',
    'room_subtotal' => 'Room charges before extras and tax.',
    'child_supplement' => 'Child supplement with the currency symbol.',
    'deposit_amount' => 'Deposit requested.',
    'balance_due' => 'Balance still to pay.',
    'valid_until' => 'The date the quotation stops being valid.',
    'quotation_notes' => 'The notes typed into the quotation.',
    'invoice_number' => 'The invoice number.',
    'check_out' => 'Check-out date, like June 3, 2026.',
    'invoice_link' => 'Link to the guest booking lookup page for this booking.',
    'refund_reference' => 'The refund reference.',
    'refund_amount_formatted' => 'Refund amount as a number.',
    'refund_reason_display' => 'The refund reason in words.',
    'refund_date_formatted' => 'The date of the refund.',
    'booking_type_label' => 'What was refunded: Room, Conference and so on.',
    'amount_due' => 'Amount still owed.',
    'due_date' => 'The date payment was due.',
    'account_reference' => 'The reference of the account being chased.',
    'pay_instructions' => 'A ready-made "how to pay" block.',
    'days_left' => 'Time left before a quotation ends, in words.',
    'quote_total' => 'The quotation total as a number.',
    'company_name' => 'The company name on the enquiry.',
    'contact_person' => 'The contact person on the enquiry.',
    'recipient_name' => 'The name of the person the quotation is for.',
    'inquiry_reference' => 'The enquiry reference.',
    'conference_room' => 'The conference room name.',
    'event_type' => 'The type of event.',
    'event_date' => 'The event date.',
    'event_time' => 'The event start and end time.',
    'event_title' => 'The event name.',
    'event_location' => 'Where the event takes place.',
    'attendees' => 'Number of attendees.',
    'attendee_count' => 'Number of attendees.',
    'rate_per_attendee' => 'Price per attendee.',
    'client_email' => 'The client email address.',
    'client_phone' => 'The client phone number.',
    'issued_date' => 'The date the document was issued.',
    'status_text' => 'The status wording, such as BALANCE DUE or PAID IN FULL.',
    'status_bg' => 'Background colour of the status label.',
    'status_fg' => 'Text colour of the status label.',
    'amount_paid' => 'Amount paid so far.',
    'total_due' => 'Total due on the invoice.',
    'room_icon_html' => 'A small room symbol used in the invoice layout.',
    'charges_table_rows' => 'The invoice charge lines.',
    'totals_rows' => 'The invoice totals rows.',
    'payment_history_section' => 'The list of payments received.',
    'bank_details' => 'The hotel bank details block (empty when none are set).',
    'invoice_terms' => 'The invoice terms block (empty when none are set).',
    'credit_note_number' => 'The credit note number.',
    'amount' => 'The credit note value.',
    'balance' => 'What is left on the credit note.',
    'amount_used' => 'How much of the credit note has been used.',
    'reason' => 'The reason the credit note was issued.',
    'reason_notes' => 'The notes typed with the credit note.',
    'expires_at' => 'The expiry date, or "No expiry".',
    'hotel_phone' => 'The hotel phone number.',
    'hotel_address' => 'The hotel address.',
    'receipt_number' => 'The receipt number.',
    'payment_reference' => 'The payment reference.',
    'payment_date' => 'The payment date.',
    'payment_method' => 'How it was paid, in words.',
    'payment_type' => 'The kind of payment, in words.',
    'payment_status' => 'The payment status, in words.',
    'payment_amount' => 'The payment amount before VAT.',
    'booking_type' => 'What the payment was for: Room, Conference, Restaurant and so on.',
    'description' => 'A short description of what was paid for.',
    'bank_details_html' => 'Bank details block. Filled in only in the Receipt PDF preview.',
    'receipt_terms' => 'Receipt wording block. Filled in only in the Receipt PDF preview.',
];

/* The set every email built from a booking can use. */
$base = ['site_name','site_url','contact_email','contact_phone','phone_main','currency_symbol','payment_policy','check_in_time','check_out_time','booking_reference','guest_name','guest_email','guest_phone','room_name','room_assignment','room_numbers','occupancy_type','check_in_date_formatted','check_out_date_formatted','number_of_nights','number_of_guests','adult_guests','child_guests','tentative_expires_at_formatted','tentative_status','child_price_multiplier','child_supplement_total_formatted','total_amount_formatted','special_requests','cancellation_reason','rate_plan_label','rate_plan_discount_formatted','package_total_formatted','packages_html','vat_number','vat_rate','vat_amount','levy_rate','levy_amount','subtotal_amount','total_amount','vat_number_html','logo_html','address'];

$groups = [
    [
        'title' => 'Shared booking tags',
        'applies' => 'Received, Confirmed, Reminder, Cancelled, the four Tentative emails, Room Invoice Email, Room Quote Email, Refund Email, Reminder 1, 2 and 3, and Quote Expiry. On the last five, booking details are blank because they are not built from a full booking: Refund and the reminders carry the guest name, email and reference only, and Quote Expiry carries the quotation fields.',
        'tags' => $base,
    ],
    [
        'title' => 'Extra tags: Reminder Email (check-in reminder)',
        'applies' => 'Reminder Email only.',
        'tags' => ['days_overdue', 'urgency_notice'],
    ],
    [
        'title' => 'Extra tags: Tentative emails',
        'applies' => 'Tentative New: tentative_duration_hours, hours_until_expiry, whatsapp_link. Tentative Reminder: whatsapp_link. Tentative Confirmed: conversion_status. Tentative Expired: no extras. All also use tentative_expires_at_formatted from the shared tags.',
        'tags' => ['tentative_duration_hours', 'hours_until_expiry', 'whatsapp_link', 'conversion_status'],
    ],
    [
        'title' => 'Extra tags: Room Quote Email',
        'applies' => 'Room Quote Email only. It also replaces total_amount, total_amount_formatted, vat_amount, vat_rate, payment_policy and the date and guest tags with quotation values.',
        'tags' => ['quotation_reference', 'quote_reference', 'check_in_date', 'check_out_date', 'nights', 'guests', 'rate_per_night', 'room_subtotal', 'child_supplement', 'deposit_amount', 'balance_due', 'valid_until', 'quotation_notes'],
    ],
    [
        'title' => 'Extra tags: Room Invoice Email',
        'applies' => 'Room Invoice Email only.',
        'tags' => ['invoice_number', 'check_out', 'invoice_link'],
    ],
    [
        'title' => 'Extra tags: Refund Email',
        'applies' => 'Refund Email only.',
        'tags' => ['refund_reference', 'refund_amount_formatted', 'refund_reason_display', 'refund_date_formatted', 'booking_type_label'],
    ],
    [
        'title' => 'Extra tags: Reminder 1, 2 and 3 (payment reminders)',
        'applies' => 'The three payment reminder emails. If one is switched off (Active unticked), that reminder is not sent.',
        'tags' => ['amount_due', 'due_date', 'days_overdue', 'invoice_number', 'account_reference', 'pay_instructions'],
    ],
    [
        'title' => 'Extra tags: Quote Expiry',
        'applies' => 'Quote Expiry email only.',
        'tags' => ['quote_reference', 'valid_until', 'days_left', 'quote_total'],
    ],
    [
        'title' => 'Conference Quote Email',
        'applies' => 'This email has its own list. Nothing from the shared set works here except the tags below.',
        'tags' => ['site_name', 'guest_name', 'contact_person', 'company_name', 'inquiry_reference', 'quotation_reference', 'quote_reference', 'conference_room', 'event_type', 'event_date', 'event_time', 'attendees', 'total_amount', 'total_amount_formatted', 'currency_symbol', 'valid_until', 'quotation_notes', 'contact_phone', 'contact_email'],
    ],
    [
        'title' => 'Event Quote Email',
        'applies' => 'Its own list.',
        'tags' => ['site_name', 'recipient_name', 'quotation_reference', 'quote_reference', 'event_title', 'event_date', 'event_time', 'event_location', 'attendee_count', 'total_amount', 'total_amount_formatted', 'currency_symbol', 'valid_until', 'quotation_notes', 'contact_phone', 'contact_email'],
    ],
    [
        'title' => 'Conference Invoice Email',
        'applies' => 'Its own list.',
        'tags' => ['site_name', 'logo_html', 'address', 'inquiry_reference', 'company_name', 'contact_person', 'conference_room', 'event_date', 'event_time', 'attendees', 'subtotal_amount', 'vat_rate', 'vat_amount', 'total_amount', 'vat_number', 'vat_number_html', 'contact_email', 'contact_phone'],
    ],
    [
        'title' => 'Credit Note Email',
        'applies' => 'Its own list.',
        'tags' => ['site_name', 'guest_name', 'credit_note_number', 'amount', 'balance', 'amount_used', 'reason', 'reason_notes', 'expires_at', 'hotel_phone', 'hotel_address', 'booking_reference', 'currency_symbol', 'contact_email', 'logo_html'],
    ],
    [
        'title' => 'Receipt Email (and the receipt WhatsApp message)',
        'applies' => 'The Receipt Email tab. The same tags work in the receipt WhatsApp message, which is edited on the Receipts page.',
        'tags' => ['site_name', 'guest_name', 'guest_email', 'guest_phone', 'receipt_number', 'booking_type', 'payment_reference', 'booking_reference', 'payment_date', 'payment_method', 'payment_type', 'payment_status', 'payment_amount', 'vat_amount', 'total_amount', 'description', 'contact_email', 'contact_phone', 'address', 'hotel_address', 'vat_number', 'vat_rate', 'vat_number_html', 'logo_html'],
    ],
    [
        'title' => 'Room Invoice PDF',
        'applies' => 'Its own list.',
        'tags' => ['invoice_number', 'issued_date', 'guest_name', 'guest_email', 'guest_phone', 'booking_reference', 'room_icon_html', 'room_name', 'check_in', 'check_out', 'nights', 'guests', 'status_text', 'status_bg', 'status_fg', 'total_due', 'amount_paid', 'balance_due', 'site_name', 'address', 'contact_email', 'contact_phone', 'vat_number_html', 'logo_html', 'currency_symbol', 'charges_table_rows', 'totals_rows', 'payment_history_section', 'bank_details', 'invoice_terms'],
    ],
    [
        'title' => 'Conference Invoice PDF',
        'applies' => 'Its own list.',
        'tags' => ['logo_html', 'site_name', 'address', 'contact_email', 'contact_phone', 'invoice_number', 'issued_date', 'status_text', 'inquiry_reference', 'company_name', 'contact_person', 'client_email', 'client_phone', 'conference_room', 'event_date', 'event_time', 'attendees', 'event_type', 'total_amount', 'amount_paid', 'balance_due'],
    ],
    [
        'title' => 'Room Quote PDF',
        'applies' => 'Its own list.',
        'tags' => ['logo_html', 'site_name', 'address', 'contact_phone', 'contact_email', 'quotation_reference', 'valid_until', 'guest_name', 'booking_reference', 'room_name', 'check_in_date', 'check_out_date', 'nights', 'guests', 'rate_per_night', 'room_subtotal', 'vat_amount', 'deposit_amount', 'total_amount', 'balance_due', 'payment_policy', 'quotation_notes'],
    ],
    [
        'title' => 'Conference Quote PDF',
        'applies' => 'Its own list.',
        'tags' => ['logo_html', 'site_name', 'address', 'contact_email', 'contact_phone', 'inquiry_reference', 'quotation_reference', 'company_name', 'contact_person', 'conference_room', 'event_date', 'event_time', 'attendees', 'deposit_amount', 'vat_amount', 'total_amount', 'valid_until', 'payment_policy', 'quotation_notes'],
    ],
    [
        'title' => 'Event Quote PDF',
        'applies' => 'Its own list.',
        'tags' => ['logo_html', 'site_name', 'address', 'contact_email', 'contact_phone', 'quotation_reference', 'recipient_name', 'event_title', 'event_date', 'event_time', 'event_location', 'attendee_count', 'rate_per_attendee', 'total_amount', 'valid_until', 'quotation_notes'],
    ],
    [
        'title' => 'Credit Note PDF',
        'applies' => 'Its own list.',
        'tags' => ['logo_html', 'site_name', 'address', 'contact_email', 'contact_phone', 'credit_note_number', 'issued_date', 'guest_name', 'guest_email', 'booking_reference', 'reason', 'reason_notes', 'expires_at', 'amount', 'amount_used', 'balance'],
    ],
    [
        'title' => 'Receipt PDF (preview only)',
        'applies' => 'These tags appear in the Receipt PDF preview. I found nothing in the system that applies this template to a real receipt, so edits here may not change the receipt PDF guests receive.',
        'tags' => ['bank_details_html', 'receipt_terms'],
    ],
];

$totalShown = 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Email Template Tags — <?= h($siteName) ?></title>
  <link rel="stylesheet" href="assets/guide-theme.css">
  <script src="assets/guide-init.js" defer></script>
</head>
<body>
<div class="wrap">

  <nav class="top">
    <a href="index.html" class="brand"><?= h($siteName) ?></a>
    <a href="index.html">All guides</a>
    <a href="99-admin-dashboard-full-guide.html">Admin reference</a>
  </nav>

  <h1>Email Template Tags</h1>
  <p>How to change the wording of the emails and PDFs guests receive, and which <code>{{tags}}</code> you can use in each one. A tag is replaced with real details when the message is sent.</p>

  <div class="facts">
    <dl>
      <dt>Who uses it</dt><dd>Managers and anyone who edits guest wording</dd>
      <dt>Where to find it</dt><dd>Admin menu &rarr; <strong>Settings</strong> &rarr; <strong>Email Templates</strong> (<code>/admin/email-templates.php</code>)</dd>
      <dt>Permission needed</dt><dd><strong>Edit message templates</strong>. Without it you can open the page and preview, but not change anything.</dd>
    </dl>
  </div>

  <div class="toc">
    <ol>
      <li><a href="#edit">How to edit a template</a></li>
      <li><a href="#tags">Tag reference</a></li>
      <li><a href="#rules">Rules the system enforces</a></li>
      <li><a href="#problems">Problems and fixes</a></li>
    </ol>
  </div>

  <h2 id="edit">How to edit a template</h2>
  <ol class="steps">
    <li>Open <strong>Settings</strong> &rarr; <strong>Email Templates</strong>.</li>
    <li>Click the tab for the message you want, for example <strong>Confirmed Email</strong> or <strong>Receipt PDF</strong>. A green dot means the template is active. A yellow dot means <em>Not configured</em>. Only the templates for the parts of the business that are switched on appear.</li>
    <li>Change the <strong>Subject line</strong> (PDF templates have one too, but it is not shown on the document).</li>
    <li>Change the <strong>HTML body</strong>. Click a tag under <strong>Placeholders</strong> to put it where your cursor is, or type <code>{{</code> and pick from the suggestions. <strong>Format</strong> tidies the layout.</li>
    <li>Optional: open <strong>Plain text version</strong> to write a text-only copy for mail programs that cannot show HTML.</li>
    <li>Tick or untick <strong>Active</strong>. When a template is not active the system uses its built-in wording instead.</li>
    <li>Click <strong>Preview</strong>. The preview also refreshes by itself as you type. If it shows <strong>Unsaved Changes</strong>, your edits are not saved yet.</li>
    <li>To test, use <strong>Send as Test Email</strong>: check the address and click <strong>Send</strong>. You must preview first. You will see <em>Sent to address!</em>, or <em>Enter a valid email address.</em>, or <em>Generate a preview first.</em> The email subject starts with [TEST]. PDF tabs send a real PDF attachment.</li>
    <li>Click <strong>Save All Templates</strong>. You will see <em>"Booking email templates updated successfully!"</em></li>
  </ol>
  <p><strong>Load Default</strong> puts the built-in wording for that one tab into the boxes (you are asked to confirm). Nothing changes until you click Save All Templates. <strong>Reset All to Defaults</strong> overwrites every template with the built-in wording after you confirm, and shows <em>"All booking email and PDF templates were reset to the default design (N templates)."</em></p>
  <div class="warn"><p>Preview fills in every tag with sample details, but a real message fills in only the tags listed for that template below. A tag that is not on the list for that template stays on the email as plain <code>{{text}}</code>. Always check the list before you use a tag.</p></div>
  <div class="note"><p>The receipt WhatsApp message is edited on <strong>Advanced</strong> &rarr; <strong>Receipts</strong>, not here.</p></div>

  <h2 id="tags">Tag reference</h2>
  <form method="get" action="">
    <p>
      <label for="q">Search for a tag or a word</label><br>
      <input type="search" id="q" name="q" value="<?= h($q) ?>" placeholder="e.g. invoice or vat_rate">
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?> <a href="12-email-templates.php">Clear</a><?php endif; ?>
    </p>
  </form>
  <?php if ($q !== ''): ?><p>Showing tags matching <strong><?= h($q) ?></strong>.</p><?php endif; ?>

<?php foreach ($groups as $g):
    $rows = [];
    foreach ($g['tags'] as $t) {
        $desc = $d[$t] ?? '';
        if ($qLow !== '' && stripos($t, $qLow) === false && stripos($desc, $qLow) === false && stripos($g['title'], $qLow) === false) {
            continue;
        }
        $rows[] = [$t, $desc];
    }
    if (!$rows) {
        continue;
    }
    $totalShown += count($rows);
?>
  <h3><?= h($g['title']) ?></h3>
  <p><?= h($g['applies']) ?></p>
  <table>
    <thead><tr><th>Tag</th><th>What it fills in</th></tr></thead>
    <tbody>
<?php foreach ($rows as $r): ?>
      <tr><td><code><?= h('{{' . $r[0] . '}}') ?></code></td><td><?= h($r[1]) ?></td></tr>
<?php endforeach; ?>
    </tbody>
  </table>
<?php endforeach; ?>
<?php if ($totalShown === 0): ?>
  <p>No tags match <strong><?= h($q) ?></strong>.</p>
<?php endif; ?>

  <h2 id="rules">Rules the system enforces</h2>
  <ul>
    <li>Every template needs a subject and an HTML body to save. Saving checks all templates, so one empty template stops the whole save.</li>
    <li>A subject can be up to 255 characters.</li>
    <li>Without <strong>Edit message templates</strong> the page is view and preview only.</li>
    <li>Amounts, names and requests inserted by tags are made safe automatically; do not add your own escaping.</li>
  </ul>

  <h2 id="problems">Problems and fixes</h2>
  <table>
    <thead><tr><th>What you see</th><th>Why</th><th>What to do</th></tr></thead>
    <tbody>
      <tr><td><em>Template name: subject and HTML body are required</em></td><td>A template has an empty subject or body.</td><td>Fill it in, or click Load Default on that tab.</td></tr>
      <tr><td><em>Template name: subject is too long (max 255 characters)</em></td><td>Subject too long.</td><td>Shorten it.</td></tr>
      <tr><td><em>You do not have permission to edit message templates.</em></td><td>Missing the permission.</td><td>Ask an administrator.</td></tr>
      <tr><td><em>No content to preview — fill in the fields or save the template first.</em></td><td>Subject or body is empty and nothing is saved.</td><td>Type content or use Load Default.</td></tr>
      <tr><td><em>Failed to send test email: ...</em></td><td>Email settings problem.</td><td>Check the email settings in Hotel Settings.</td></tr>
      <tr><td>A <code>{{tag}}</code> shows as text in a real email</td><td>That tag is not available for that template.</td><td>Use a tag from the list for that template.</td></tr>
    </tbody>
  </table>

  <h2 id="related">Related guides</h2>
  <ul>
    <li><a href="13-finance-payments.html">Finance and Payments</a></li>
    <li><a href="07-reception-bookings.html">Reception and Bookings</a></li>
  </ul>

  <footer>Updated October 2026. Describes the system as it is set up today.</footer>
</div>
</body>
</html>
