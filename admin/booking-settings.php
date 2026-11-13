<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */
require_once __DIR__ . '/../includes/booking-functions.php';
require_once __DIR__ . '/../config/email.php';
// Hotel Settings change log (owner 2026-11-14): switched on only around the save handlers below, off before anything else.
require_once __DIR__ . '/../includes/settings-change-log.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$template_preview = null;

/* Reaching booking settings is not the same as rewriting the wording every
   guest receives. Without this the email/PDF template editors are hidden and
   the panel is preview-only; the save and reset actions are refused below. */
$canEditTemplates = hasPermission((int)($user['id'] ?? 0), 'edit_templates');
/* Permission tiers (owner 2026-11-11): Hotel Settings stays open to managers, but the switches that can take the website
   or bookings offline, change booking numbering or the service channels need site_switches (administrators only), and the
   email server (SMTP) needs email_server_settings (super admin only). Each handler refuses on the server; the forms render
   read-only. */
$canSiteSwitches = hasPermission((int)($user['id'] ?? 0), 'site_switches');
$canEmailServer = hasPermission((int)($user['id'] ?? 0), 'email_server_settings');
$rh_site_switch_refusal = 'Only administrators can change this. Nothing was changed.';
$rhSwDis = $canSiteSwitches ? '' : ' disabled'; // read-only render for everyone below administrator
$rh_site_switch_note = $canSiteSwitches ? '' : '<p class="help-text"><i class="fas fa-lock"></i> Only administrators can change this.</p>';

/* Email & PDF templates have their own page (email-templates.php includes this file with
   RH_TEMPLATES_PAGE), so template access can be given without the rest of Hotel Settings.
   That page renders only the template editor and refuses every other settings POST. */
$rh_templates_only = defined('RH_TEMPLATES_PAGE');
$rh_self = $rh_templates_only ? 'email-templates.php' : 'booking-settings.php';
if (!$rh_templates_only && ($_GET['section'] ?? '') === 'email-templates') {
    header('Location: email-templates.php');
    exit;
}
if ($rh_templates_only && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rh_template_posts = ['booking_email_template_preview', 'send_test_email', 'reset_all_booking_templates_to_defaults', 'booking_email_templates', 'save_receipt_whatsapp'];
    if (!array_intersect($rh_template_posts, array_keys($_POST))) {
        $_SERVER['REQUEST_METHOD'] = '__BLOCKED__';
    }
}
$default_site_maintenance_message = 'Our website is temporarily unavailable while we complete scheduled maintenance. Please check back shortly.';

// Module flags — gate settings sections/templates by the active business
// preset. Structural UI only; stored settings/templates are never mutated
// for hidden sections (hidden checkbox values are preserved via hidden inputs,
// and the template save loop skips filtered-out templates entirely).
$mod_bookings   = function_exists('moduleEnabled') && moduleEnabled('bookings');
$mod_pos        = function_exists('moduleEnabled') && moduleEnabled('pos');
$mod_conference = function_exists('moduleEnabled') && moduleEnabled('conference');
$mod_gym        = function_exists('moduleEnabled') && moduleEnabled('gym');
$mod_events     = function_exists('isEventsEnabled') && isEventsEnabled();
$booking_template_defs_master = [
    'booking_received' => 'Booking Received Email',
    'booking_confirmed' => 'Booking Confirmed Email',
    'booking_reminder' => 'Check-in Reminder Email',
    'booking_cancelled' => 'Booking Cancelled Email',
    'payment_invoice' => 'Room Invoice Email',
    'payment_invoice_document' => 'Room Invoice PDF',
    'conference_invoice' => 'Conference Invoice Email',
    'conference_invoice_document' => 'Conference Invoice PDF',
    'tentative_booking_created' => 'Tentative Booking Created Email',
    'tentative_booking_reminder' => 'Tentative Booking Reminder Email',
    'tentative_booking_expired' => 'Tentative Booking Expired Email',
    'tentative_booking_converted' => 'Tentative Booking Confirmed Email',
    'tentative_quotation' => 'Room Quotation Email',
    'tentative_quotation_document' => 'Room Quotation PDF',
    'conference_quotation' => 'Conference Quotation Email',
    'conference_quotation_document' => 'Conference Quotation PDF',
    'event_quotation' => 'Event Quotation Email',
    'event_quotation_document' => 'Event Quotation PDF',
    'credit_note' => 'Credit Note Email',
    'credit_note_document' => 'Credit Note PDF',
    'refund_notification' => 'Refund Notification Email',
    'payment_receipt' => 'Payment Receipt Email',
    'payment_receipt_document' => 'Payment Receipt PDF',
    'payment_reminder_1' => 'Payment Reminder 1 (Friendly) Email',
    'payment_reminder_2' => 'Payment Reminder 2 (Firm) Email',
    'payment_reminder_3' => 'Payment Reminder 3 (Final) Email',
    'quotation_expiry_reminder' => 'Quotation Expiry Reminder Email',
];

// Filter the template editor to templates the active preset can actually send.
// Hidden templates keep their stored content untouched — the save loop below
// iterates this same filtered list, so it never overwrites what it can't see.
$booking_template_module_map = [
    'booking_received' => 'bookings', 'booking_confirmed' => 'bookings',
    'booking_reminder' => 'bookings', 'booking_cancelled' => 'bookings',
    'payment_invoice' => 'bookings', 'payment_invoice_document' => 'bookings',
    'tentative_booking_created' => 'bookings', 'tentative_booking_reminder' => 'bookings',
    'tentative_booking_expired' => 'bookings', 'tentative_booking_converted' => 'bookings',
    'tentative_quotation' => 'bookings', 'tentative_quotation_document' => 'bookings',
    'conference_invoice' => 'conference', 'conference_invoice_document' => 'conference',
    'conference_quotation' => 'conference', 'conference_quotation_document' => 'conference',
    'event_quotation' => 'events', 'event_quotation_document' => 'events',
    'credit_note' => 'ar', 'credit_note_document' => 'ar',
    'payment_reminder_1' => 'ar', 'payment_reminder_2' => 'ar', 'payment_reminder_3' => 'ar',
    'quotation_expiry_reminder' => 'bookings',
    // refund_notification / payment_receipt(+document): every preset.
];
$booking_template_defs_master = array_filter(
    $booking_template_defs_master,
    function (string $tkey) use ($booking_template_module_map, $mod_bookings, $mod_conference, $mod_events): bool {
        $need = $booking_template_module_map[$tkey] ?? null;
        return match ($need) {
            'bookings'   => $mod_bookings,
            'conference' => $mod_conference,
            'events'     => $mod_events,
            'ar'         => $mod_bookings || $mod_conference, // credit notes: AR businesses
            default      => true,
        };
    },
    ARRAY_FILTER_USE_KEY
);
$booking_document_template_keys = [
    'payment_invoice_document',
    'conference_invoice_document',
    'tentative_quotation_document',
    'conference_quotation_document',
    'event_quotation_document',
    'credit_note_document',
    'payment_receipt_document',
];
$booking_template_short_names = [
    'booking_received' => 'Received Email',
    'booking_confirmed' => 'Confirmed Email',
    'booking_reminder' => 'Reminder Email',
    'booking_cancelled' => 'Cancelled Email',
    'payment_invoice' => 'Room Invoice Email',
    'payment_invoice_document' => 'Room Invoice PDF',
    'conference_invoice' => 'Conference Invoice Email',
    'conference_invoice_document' => 'Conference Invoice PDF',
    'tentative_booking_created' => 'Tentative New',
    'tentative_booking_reminder' => 'Tentative Reminder',
    'tentative_booking_expired' => 'Tentative Expired',
    'tentative_booking_converted' => 'Tentative Confirmed',
    'tentative_quotation' => 'Room Quote Email',
    'tentative_quotation_document' => 'Room Quote PDF',
    'conference_quotation' => 'Conference Quote Email',
    'conference_quotation_document' => 'Conference Quote PDF',
    'event_quotation' => 'Event Quote Email',
    'event_quotation_document' => 'Event Quote PDF',
    'credit_note' => 'Credit Note Email',
    'credit_note_document' => 'Credit Note PDF',
    'refund_notification' => 'Refund Email',
    'payment_receipt' => 'Receipt Email',
    'payment_receipt_document' => 'Receipt PDF',
    'payment_reminder_1' => 'Reminder 1 (Friendly)',
    'payment_reminder_2' => 'Reminder 2 (Firm)',
    'payment_reminder_3' => 'Reminder 3 (Final)',
    'quotation_expiry_reminder' => 'Quote Expiry',
];

// Handle enable/disable via GET parameter
if (isset($_GET['enable'])) {
    // A state change must never fire from a bare link (cross-site image/link): require the CSRF token.
    if (validateCsrfToken((string)($_GET['csrf_token'] ?? '')) && $canSiteSwitches) { // owner 2026-11-11: site_switches
        rh_settings_log_begin($user);
        try {
            updateSetting('booking_system_enabled', '1');
        } finally {
            rh_settings_log_end();
        }
    }
    header('Location: booking-settings.php');
    exit;
}

// CSRF guard — all state-changing POST handlers on this page (skip read-only ajax preview)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_ajax_preview'])) {
    $isAjaxBS = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($isAjaxBS) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Security token invalid. Refresh the page.']);
            exit;
        }
        $error = 'Security token invalid. Refresh the page.';
        $_SERVER['REQUEST_METHOD'] = '__BLOCKED__'; // prevent all subsequent POST handlers
    }
}

// Settings change log, first span: the save handlers from here to "Get booking system settings" (no exits in between).
// Ended explicitly below; the scheduler also ends it first thing (includes/auto-scheduler.php).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rh_settings_log_begin($user);
}

// Handle one-switch booking system toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_booking_system'])) {
    if (!$canSiteSwitches) {
        $error = $rh_site_switch_refusal;
    } else {
    $enabled = isset($_POST['booking_system_enabled']) ? '1' : '0';
    updateSetting('booking_system_enabled', $enabled);
    $message = $enabled === '1'
        ? 'Booking system enabled successfully!'
        : 'Booking system disabled successfully!';
    }
}

// Handle tentative bookings toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_tentative_bookings'])) {
    $tentativeEnabled = isset($_POST['tentative_bookings_enabled']) ? '1' : '0';
    updateSetting('tentative_bookings_enabled', $tentativeEnabled);
    global $_SITE_SETTINGS;
    if (isset($_SITE_SETTINGS['tentative_bookings_enabled'])) {
        unset($_SITE_SETTINGS['tentative_bookings_enabled']);
    }
    deleteCache('setting_tentative_bookings_enabled');
    $message = $tentativeEnabled === '1'
        ? 'Tentative bookings enabled — guests can hold dates without immediate payment.'
        : 'Tentative bookings disabled — only standard bookings will appear on the website and admin forms.';
}

// Cancellation handling mode (what happens to the bill and the money when a booking is cancelled)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_cancellation_refund_mode'])) {
    $cancelModes = ['void_refund_all', 'void_keep_credit', 'keep_first_night'];
    $newCancelMode = (string)($_POST['cancellation_refund_mode'] ?? '');
    $newRules = [];
    $ruleOptions = rh_refund_rule_options();
    $rulesValid = true;
    foreach ($ruleOptions as $ruleKey => $ruleChoices) {
        $posted = (string)($_POST[$ruleKey] ?? '');
        if (!in_array($posted, $ruleChoices, true)) {
            $rulesValid = false;
            break;
        }
        $newRules[$ruleKey] = $posted;
    }
    if (!hasPermission((int)($user['id'] ?? 0), 'finance_settings')) {
        $error = 'You do not have permission to change refund and cancellation settings.';
    } elseif (!in_array($newCancelMode, $cancelModes, true) || !$rulesValid) {
        $error = 'Invalid refund or cancellation option.';
    } else {
        $oldCancelMode = getCancellationRefundMode();
        $oldRules = [];
        foreach ($ruleOptions as $ruleKey => $ruleChoices) {
            $oldRules[$ruleKey] = rh_refund_rule($ruleKey);
        }
        updateSetting('cancellation_refund_mode', $newCancelMode);
        foreach ($newRules as $ruleKey => $ruleVal) {
            updateSetting($ruleKey, $ruleVal);
        }
        if (function_exists('rh_log_event')) {
            rh_log_event('admin/' . basename(__FILE__, '.php'), 'warning', 'Refund and cancellation settings changed', [
                'user' => $user['username'] ?? '',
                'user_id' => $user['id'] ?? null,
                'from' => ['cancellation_refund_mode' => $oldCancelMode] + $oldRules,
                'to' => ['cancellation_refund_mode' => $newCancelMode] + $newRules,
            ]);
        }
        $message = 'Refund and cancellation settings saved.';
    }
}

// Group no-show / expiry charge (setting group_noshow_charge; code default '0' = Off). Its own form, so no other save
// ever writes it; written only when the choice actually changes. Money setting: finance_settings, like the refund rules.
$rh_group_noshow_choices = [
    '0'           => ['Off - bill the booked stay, as today', 'A no-show or expired room in a group keeps billing its booked stay, exactly as before.'],
    'none'        => ['On - charge nothing', 'The room stops billing its stay and is charged nothing; with no payment on it, it comes off the group invoice.'],
    'first_night' => ['On - charge the first night', 'The room stops billing its stay and is charged its first night (booked total incl. VAT and levy, divided by the nights).'],
    'full'        => ['On - charge the full stay', 'The room stops billing its stay and is charged the full booked stay as one charge.'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group_noshow_charge'])) {
    $newGns = (string)($_POST['group_noshow_charge'] ?? '');
    $oldGns = rh_group_noshow_charge_mode();
    $oldGns = $oldGns === '' ? '0' : $oldGns;
    if (!hasPermission((int)($user['id'] ?? 0), 'finance_settings')) {
        $error = 'You do not have permission to change refund and cancellation settings.';
    } elseif (!isset($rh_group_noshow_choices[$newGns])) {
        $error = 'Invalid no-show charge option.';
    } elseif ($newGns === $oldGns) {
        $message = 'No-show charge for group rooms unchanged.';
    } else {
        updateSetting('group_noshow_charge', $newGns);
        if (function_exists('rh_log_event')) {
            rh_log_event('admin/' . basename(__FILE__, '.php'), 'warning', 'Group no-show / expiry charge changed', [
                'user' => $user['username'] ?? '', 'user_id' => $user['id'] ?? null, 'from' => $oldGns, 'to' => $newGns,
            ]);
        }
        $message = 'No-show charge for group rooms saved. It applies to rooms that become a no-show or expire from now on.';
    }
}

// Hotel details & policies (registry-driven; see admin/includes/hotel-details-settings.php)
require_once __DIR__ . '/includes/hotel-details-settings.php';
require_once __DIR__ . '/includes/review-sources-settings.php';
$mod_website_cms = !function_exists('moduleEnabled') || moduleEnabled('website_cms');
// Review sources (Google / Brave API keys for the Reviews web importer)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_review_sources']) && $mod_website_cms) {
    [$rsMessage, $rsError] = rh_review_sources_save($_POST, $user);
    if ($rsError !== '') {
        $error = $rsError;
    } else {
        $message = $rsMessage; // built from escaped parts in rh_review_sources_save()
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_hotel_details'])) {
    [$hdMessage, $hdError] = rh_hotel_details_save($_POST, $user);
    if ($hdError !== '') {
        $error = $hdError;
    } else {
        $message = $hdMessage;
    }
}

// Booking reference prefix (new references only; existing ones never change)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_booking_reference_prefix'])) {
    if (!$canSiteSwitches) {
        $error = $rh_site_switch_refusal;
    }
    $newPrefix = strtoupper(trim((string)($_POST['booking_reference_prefix'] ?? '')));
    if (!$canSiteSwitches) {
        // refused above (owner 2026-11-11)
    } elseif (!preg_match('/^[A-Z0-9]{2,6}$/', $newPrefix)) {
        $error = 'The booking reference prefix must be 2 to 6 letters or digits (for example HTL).';
    } else {
        $oldPrefix = rh_booking_reference_prefix();
        updateSetting('booking_reference_prefix', $newPrefix);
        if (function_exists('rh_log_event')) {
            rh_log_event('admin/' . basename(__FILE__, '.php'), 'info', 'Booking reference prefix changed', [
                'user' => $user['username'] ?? '', 'user_id' => $user['id'] ?? null, 'from' => $oldPrefix, 'to' => $newPrefix,
            ]);
        }
        $message = 'Booking reference prefix saved. New bookings will look like ' . $newPrefix . date('Y') . '012345.';
    }
}

// Document branding (colours, footer line, terms, logo size on every emailed PDF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_document_branding'])) {
    $docBrandKeys = ['brand_primary_color', 'brand_accent_color', 'document_footer_text', 'document_terms_text', 'document_logo_height_mm'];
    if (!hasPermission((int)($user['id'] ?? 0), 'finance_settings')) {
        $error = 'You do not have permission to change document branding.';
    } else {
        $newBrand = [];
        $brandErrors = [];
        foreach (['brand_primary_color' => 'Primary colour', 'brand_accent_color' => 'Accent colour'] as $ck => $cl) {
            $raw = trim((string)($_POST[$ck] ?? ''));
            if ($raw === '') {
                $newBrand[$ck] = '';
            } elseif (preg_match('/^#[0-9a-fA-F]{6}$/', $raw)) {
                $newBrand[$ck] = strtolower($raw);
            } else {
                $brandErrors[] = $cl . ' must be a hex colour such as #524b3f.';
            }
        }
        $newBrand['document_footer_text'] = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($_POST['document_footer_text'] ?? ''))), 0, 160);
        $newBrand['document_terms_text'] = mb_substr(trim((string)($_POST['document_terms_text'] ?? '')), 0, 600);
        $logoMm = trim((string)($_POST['document_logo_height_mm'] ?? ''));
        if ($logoMm === '') {
            $newBrand['document_logo_height_mm'] = '';
        } elseif (is_numeric($logoMm) && (float)$logoMm >= 10 && (float)$logoMm <= 40) {
            $newBrand['document_logo_height_mm'] = (string)round((float)$logoMm, 1);
        } else {
            $brandErrors[] = 'Logo height must be between 10 and 40 mm.';
        }
        if ($brandErrors) {
            $error = implode(' ', $brandErrors);
        } else {
            $oldBrand = [];
            foreach ($docBrandKeys as $bk) {
                $oldBrand[$bk] = (string)getSetting($bk, '');
                updateSetting($bk, $newBrand[$bk]); // also clears the settings cache copy
            }
            if (function_exists('hotel_brand_tokens')) {
                hotel_brand_tokens(true);
            }
            if (function_exists('rh_log_event')) {
                rh_log_event('admin/' . basename(__FILE__, '.php'), 'info', 'Document branding changed', [
                    'user' => $user['username'] ?? '',
                    'user_id' => $user['id'] ?? null,
                    'from' => $oldBrand,
                    'to' => $newBrand,
                ]);
            }
            $message = 'Document branding saved. New PDFs use it straight away.';
        }
    }
}

// Handle one-click full frontend maintenance mode toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_site_maintenance'])) {
    if (!$canSiteSwitches) {
        $error = $rh_site_switch_refusal;
    } else {
    $wasEnabled = in_array(strtolower(trim((string)getSetting('site_maintenance_enabled', '0'))), ['1', 'true', 'on', 'yes'], true);
    $enabled = isset($_POST['site_maintenance_enabled']) ? '1' : '0';
    updateSetting('site_maintenance_enabled', $enabled);

    if (function_exists('rh_log_event')) {
        rh_log_event('admin/' . basename(__FILE__, '.php'), $enabled === '1' ? 'warning' : 'info', $enabled === '1'
            ? 'Frontend maintenance mode enabled'
            : 'Frontend maintenance mode disabled', [
            'user' => $user['username'] ?? '',
            'user_id' => $user['id'] ?? null,
            'previous_state' => $wasEnabled ? 'enabled' : 'disabled',
            'new_state' => $enabled === '1' ? 'enabled' : 'disabled',
        ]);
    }

    $message = $enabled === '1'
        ? 'Frontend maintenance mode enabled. Public pages now show the maintenance page.'
        : 'Frontend maintenance mode disabled. Public pages are live again.';
    }
}

// Save custom maintenance message shown during maintenance mode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_maintenance_message'])) {
    if (!$canSiteSwitches) {
        $error = $rh_site_switch_refusal;
    } else {
    $maintenanceMessageInput = trim((string)($_POST['site_maintenance_message'] ?? ''));
    if ($maintenanceMessageInput === '') {
        $maintenanceMessageInput = $default_site_maintenance_message;
    }

    $maintenanceMessageInput = mb_substr($maintenanceMessageInput, 0, 1000);
    updateSetting('site_maintenance_message', $maintenanceMessageInput);

    if (function_exists('rh_log_event')) {
        rh_log_event('admin/' . basename(__FILE__, '.php'), 'info', 'Frontend maintenance message updated', [
            'user' => $user['username'] ?? '',
            'user_id' => $user['id'] ?? null,
            'message_length' => strlen($maintenanceMessageInput),
        ]);
    }

    $message = 'Frontend maintenance message updated successfully!';
    }
}

// Handle disable via POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_booking'])) {
    if (!$canSiteSwitches) {
        $error = $rh_site_switch_refusal;
    } else {
    updateSetting('booking_system_enabled', '0');
    $message = "Booking system disabled successfully!";
    }
}

// Handle disabled mode settings
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_disabled_action'])) {
    if (!$canSiteSwitches) {
        $error = $rh_site_switch_refusal;
    }
    $newDisabledAction = trim((string)$_POST['booking_disabled_action']);
    $newRedirectUrl = trim((string)($_POST['booking_disabled_redirect_url'] ?? ''));
    if (!in_array($newDisabledAction, ['message', 'contact', 'redirect'], true)) {
        $newDisabledAction = in_array((string)getSetting('booking_disabled_action', 'message'), ['message', 'contact', 'redirect'], true)
            ? (string)getSetting('booking_disabled_action', 'message') : 'message';
    }
    // Redirect target: site-relative path or http(s) URL only (never javascript:/data:, never //host).
    if (!$canSiteSwitches) {
        // refused above (owner 2026-11-11)
    } elseif (mb_strlen($newRedirectUrl) > 500) {
        $error = 'Redirect URL is too long (500 characters maximum).';
    } elseif ($newRedirectUrl !== '' && !preg_match('#^https?://#i', $newRedirectUrl) && !preg_match('#^/(?!/)#', $newRedirectUrl)) {
        $error = 'Redirect URL must start with http://, https:// or a single /.';
    } else {
        updateSetting('booking_disabled_action', $newDisabledAction);
        updateSetting('booking_disabled_message', mb_substr(trim((string)($_POST['booking_disabled_message'] ?? '')), 0, 1000));
        if (isset($_POST['booking_disabled_redirect_url'])) {
            updateSetting('booking_disabled_redirect_url', $newRedirectUrl);
        }
        $message = "Disabled mode settings updated successfully!";
    }
}
rh_settings_log_end(); // end of the first span: the previews and test sends below never log settings

// Get booking system settings
$booking_enabled = isBookingEnabled();
$disabled_action = getBookingDisabledAction();
$disabled_message = getBookingDisabledMessage();
$disabled_redirect_url = getSetting('booking_disabled_redirect_url', defined('BASE_URL') ? BASE_URL : '/');
$site_maintenance_enabled = in_array(strtolower(trim((string)getSetting('site_maintenance_enabled', '0'))), ['1', 'true', 'on', 'yes'], true);
$site_maintenance_message = trim((string)getSetting('site_maintenance_message', $default_site_maintenance_message));
if ($site_maintenance_message === '') {
    $site_maintenance_message = $default_site_maintenance_message;
}

// -----------------------------------------------------------------------
// AJAX: Preview — returns JSON, exits immediately (no HTML page)
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_email_template_preview'], $_POST['_ajax_preview'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $ajaxKey = trim((string)($_POST['booking_email_template_preview'] ?? ''));
        $ajaxValidKeys = array_keys($booking_template_defs_master);
        if (!in_array($ajaxKey, $ajaxValidKeys, true)) {
            throw new Exception('Invalid template key');
        }

        $ajaxSubject  = trim((string)($_POST[$ajaxKey . '_subject']   ?? ''));
        $ajaxHtmlBody = trim((string)($_POST[$ajaxKey . '_html_body'] ?? ''));
        $ajaxTextBody = trim((string)($_POST[$ajaxKey . '_text_body'] ?? ''));

        // Check for unsaved changes by comparing with DB template
        $existing = function_exists('getBookingEmailTemplateConfig') ? getBookingEmailTemplateConfig($ajaxKey, []) : [];
        $hasUnsavedChanges = false;

        if ($ajaxSubject === '' || $ajaxHtmlBody === '') {
            if (!empty($existing['subject'])) {
                $ajaxSubject  = $existing['subject'];
            }
            if (!empty($existing['html_body'])) {
                $ajaxHtmlBody = $existing['html_body'];
            }
            if ($ajaxTextBody === '' && !empty($existing['text_body'])) {
                $ajaxTextBody = $existing['text_body'];
            }
        } else {
            // Form has content - check if it differs from saved template
            $savedSubject = trim((string)($existing['subject'] ?? ''));
            $savedHtml = trim((string)($existing['html_body'] ?? ''));
            $savedText = trim((string)($existing['text_body'] ?? ''));

            if ($savedSubject !== $ajaxSubject || $savedHtml !== $ajaxHtmlBody || $savedText !== $ajaxTextBody) {
                $hasUnsavedChanges = true;
            }
        }

        if ($ajaxSubject === '' || $ajaxHtmlBody === '') {
            throw new Exception('No content to preview — fill in the fields or save the template first.');
        }

        $ajaxAddressParts = [
            trim((string)getSetting('address_line1', '')),
            trim((string)getSetting('address_line2', '')),
            trim((string)getSetting('address_country', '')),
        ];
        $ajaxAddressParts = array_values(array_filter($ajaxAddressParts, static fn(string $value): bool => $value !== ''));
        $ajaxAddress = implode(', ', $ajaxAddressParts);
        if ($ajaxAddress === '') {
            $ajaxAddress = (string)getSetting('hotel_address', getSetting('address', ''));
        }

        $ajaxVars = [
            '{{site_name}}'                => (string)getSetting('site_name', 'Hotel'),
            '{{site_url}}'                 => (string)getSetting('site_url', ''),
            '{{booking_reference}}'        => 'RBH2026423468',
            '{{inquiry_reference}}'        => 'CONF-2026-PREVIEW-001',
            '{{guest_name}}'               => 'Jane Doe',
            '{{guest_email}}'              => 'jane.doe@example.com',
            '{{guest_phone}}'              => '+27 82 555 0000',
            '{{recipient_name}}'           => 'Jane Doe',
            '{{contact_person}}'           => 'Jane Doe',
            '{{company_name}}'             => 'Mwai Consulting Ltd',
            '{{room_name}}'                => 'QA Joined Room Test - QA-JOIN-101A + QA-JOIN-101B',
            '{{booked_rate}}'              => 'Superior Suite - ' . (string)getSetting('currency_symbol', 'MWK') . '356,000 x 3 nights (a night, incl. VAT and tourism levy)',
            '{{conference_room}}'          => 'Baobab Conference Suite',
            '{{event_title}}'              => 'Sunset Jazz Night',
            '{{event_location}}'           => 'Beachfront Pavilion',
            '{{check_in_date_formatted}}'  => date('F j, Y', strtotime('+14 days')),
            '{{check_out_date_formatted}}' => date('F j, Y', strtotime('+16 days')),
            '{{check_in_date}}'            => date('l, F j, Y', strtotime('+14 days')),
            '{{check_out_date}}'           => date('l, F j, Y', strtotime('+16 days')),
            '{{check_in}}'                 => date('j F Y', strtotime('+14 days')),
            '{{check_out}}'                => date('j F Y', strtotime('+16 days')),
            '{{event_date}}'               => date('l, F j, Y', strtotime('+30 days')),
            '{{event_time}}'               => '09:00 - 17:00',
            '{{number_of_nights}}'         => '2',
            '{{nights}}'                   => '2',
            '{{number_of_guests}}'         => '2',
            '{{guests}}'                   => '2 adults',
            '{{adult_guests}}'             => '2',
            '{{child_guests}}'             => '0',
            '{{attendees}}'                => '60',
            '{{attendee_count}}'           => '4',
            '{{total_amount_formatted}}'   => number_format(4500, 2),
            '{{total_amount}}'             => (string)getSetting('currency_symbol', 'MWK') . number_format(4500, 2),
            '{{currency_symbol}}'          => (string)getSetting('currency_symbol', 'MWK'),
            '{{contact_email}}'            => (string)(getEmailSetting('email_from_email') ?: getSetting('contact_email', 'reservations@example.com')),
            '{{contact_phone}}'            => (string)getSetting('phone_main', ''),
            '{{phone_main}}'               => (string)getSetting('phone_main', ''),
            '{{payment_policy}}'           => 'Full payment is due 48 hours before check-in.',
            '{{check_in_time}}'            => (string)getSetting('check_in_time', '2:00 PM'),
            '{{check_out_time}}'           => (string)getSetting('check_out_time', '11:00 AM'),
            '{{cancellation_reason}}'      => 'Requested by guest.',
            '{{special_requests}}'         => 'Late check-in preferred.',
            '{{tentative_expires_at_formatted}}' => date('F j, Y g:i A', strtotime('+2 days')),
            '{{quotation_reference}}'      => 'QT-RBH-2026-PREVIEW-001',
            '{{quote_reference}}'          => 'QT-RBH-2026-PREVIEW-001',
            '{{valid_until}}'              => date('F j, Y', strtotime('+7 days')),
            '{{quotation_notes}}'          => 'Please reply by the validity date to secure your booking.',
            '{{rate_per_night}}'           => (string)getSetting('currency_symbol', 'MWK') . number_format(2250, 2),
            '{{room_subtotal}}'            => (string)getSetting('currency_symbol', 'MWK') . number_format(4500, 2),
            '{{vat_amount}}'               => (string)getSetting('currency_symbol', 'MWK') . number_format(675, 2),
            '{{vat_rate}}'                 => '15',
            '{{child_supplement}}'         => (string)getSetting('currency_symbol', 'MWK') . '0',
            '{{deposit_amount}}'           => (string)getSetting('currency_symbol', 'MWK') . number_format(1000, 2),
            '{{total_due}}'                => (string)getSetting('currency_symbol', 'MWK') . number_format(5175, 2),
            '{{amount_paid}}'              => (string)getSetting('currency_symbol', 'MWK') . number_format(1000, 2),
            '{{balance_due}}'              => (string)getSetting('currency_symbol', 'MWK') . number_format(4175, 2),
            '{{amount_due}}'               => number_format(4175, 2),
            '{{due_date}}'                 => date('F j, Y', strtotime('-3 days')),
            '{{days_overdue}}'             => '3',
            '{{account_reference}}'        => 'BK-PREVIEW-001',
            '{{days_left}}'                => '2 days from now',
            '{{quote_total}}'              => number_format(4500, 2),
            '{{pay_instructions}}'         => '<p style="margin:0 0 8px;"><strong>How to pay:</strong> bank transfer or at reception. Please quote your account reference.</p>',
            '{{invoice_number}}'           => 'INV-2026-000001',
            '{{issued_date}}'              => date('j F Y'),
            '{{status_text}}'              => 'BALANCE DUE',
            '{{client_email}}'             => 'events@example.com',
            '{{client_phone}}'             => '+27 82 444 2211',
            '{{event_type}}'               => 'Corporate Seminar',
            '{{rate_per_attendee}}'        => (string)getSetting('currency_symbol', 'MWK') . number_format(750, 2),
            '{{payment_reference}}'        => 'PAY-2026-PREVIEW-001',
            '{{payment_date}}'             => date('d M Y'),
            '{{payment_method}}'           => 'Bank Transfer',
            '{{payment_type}}'             => 'Full Payment',
            '{{payment_status}}'           => 'Completed',
            '{{payment_amount}}'           => (string)getSetting('currency_symbol', 'MWK') . number_format(4500, 2),
            '{{receipt_number}}'           => 'RCP-2026-000042',
            '{{booking_type}}'             => 'Restaurant',
            '{{description}}'              => 'Restaurant order preview for table service payment.',
            '{{bank_details_html}}'        => '<div style="background:#FCFAF7;padding:7px 10px;border-top:2px solid #D5B37C;"><p style="margin:0 0 4px;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#20303E;font-weight:700;">Bank Details</p><p style="margin:0;font-size:6px;color:#1E2430;">Bank: Preview Bank<br>Account No.: 00123456789</p></div>',
            '{{receipt_terms}}'            => '<p style="margin:0;font-size:6px;line-height:1.5;color:#5F655F;">Thank you for your payment. Please retain this receipt for your records.</p>',
            '{{credit_note_number}}'       => 'CN-RBH-2026-001',
            '{{amount}}'                   => (string)getSetting('currency_symbol', 'MWK') . number_format(1200, 2),
            '{{balance}}'                  => (string)getSetting('currency_symbol', 'MWK') . number_format(850, 2),
            '{{amount_used}}'              => (string)getSetting('currency_symbol', 'MWK') . number_format(350, 2),
            '{{reason}}'                   => 'Overpayment adjustment',
            '{{reason_notes}}'             => 'Issued after reservation amount was corrected.',
            '{{expires_at}}'               => date('F j, Y', strtotime('+90 days')),
            '{{refund_reference}}'         => 'REF-2026-000042',
            '{{refund_amount_formatted}}'  => number_format(1200, 2),
            '{{refund_reason_display}}'    => 'Early Check-Out',
            '{{refund_date_formatted}}'    => date('F j, Y'),
            '{{booking_type_label}}'       => 'Room',
            '{{address}}'                  => $ajaxAddress,
            '{{hotel_phone}}'              => (string)getSetting('phone_main', ''),
            '{{hotel_address}}'            => $ajaxAddress,
            '{{logo_html}}'                => (function (): string {
                $url      = function_exists('getHotelLogoUrl') ? getHotelLogoUrl() : '';
                $siteName = (string)getSetting('site_name', 'Hotel');
                return $url !== '' ? '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" style="max-width:160px;height:auto;display:block;margin:0 auto;">' : '';
            })(),
            '{{subtotal_amount}}'          => (string)getSetting('currency_symbol', 'MWK') . number_format(4500, 2),
            '{{vat_number}}'               => (string)getSetting('vat_number', ''),
            '{{vat_number_html}}'          => (function (): string {
                $n = (string)getSetting('vat_number', '');
                return $n !== '' ? '<p style="margin:8px 0 0;font-size:11px;color:#9b8f7e;text-align:center;">VAT Reg. No.: ' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            })(),
            '{{levy_rate}}'                => '1.0',
            '{{levy_amount}}'              => (string)getSetting('currency_symbol', 'MWK') . number_format(45, 2),
        ];

        $ajaxResSubject  = strtr($ajaxSubject,  $ajaxVars);
        $ajaxResHtmlBody = strtr($ajaxHtmlBody, $ajaxVars);
        $ajaxResTextBody = strtr($ajaxTextBody, $ajaxVars);

        if ($ajaxKey === 'payment_invoice_document') {
            $invoiceLogoUrl = function_exists('hotel_invoice_logo_src')
                ? hotel_invoice_logo_src()
                : '';

            $invoiceLogoHtml = $invoiceLogoUrl !== ''
                ? '<img src="' . htmlspecialchars($invoiceLogoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars((string)getSetting('site_name', 'Hotel'), ENT_QUOTES, 'UTF-8') . '" height="116" style="height:116px;width:auto;display:block;margin:0 auto;">'
                : '';
            $invoiceRoomIconHtml = '<span style="color:#8F6A35;font-size:11px;vertical-align:middle;">&#9679;</span>';
            $invoiceChargesRows = ''
                . '<tr>'
                . '<td width="58%" style="padding:5px 7px;border-bottom:1px solid #ECE2D7;font-size:7px;color:#1F1C17;line-height:1.3;">Deluxe Ocean Suite - Accommodation</td>'
                . '<td width="8%" style="padding:5px 7px;border-bottom:1px solid #ECE2D7;font-size:7px;color:#6C6258;text-align:center;">2</td>'
                . '<td width="16%" style="padding:5px 7px;border-bottom:1px solid #ECE2D7;font-size:7px;color:#6C6258;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 2,250.00</td>'
                . '<td width="18%" style="padding:5px 7px;border-bottom:1px solid #ECE2D7;font-size:7px;color:#1F1C17;font-weight:600;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 4,500.00</td>'
                . '</tr>';
            $invoiceTotalsRows = ''
                . '<tr>'
                . '<td colspan="3" style="padding:5px 7px 2px;border-top:1px solid #DCCFC2;font-size:7px;color:#6C6258;text-align:right;">Subtotal</td>'
                . '<td width="18%" style="padding:5px 7px 2px;border-top:1px solid #DCCFC2;font-size:7px;color:#1F1C17;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 4,500.00</td>'
                . '</tr>'
                . '<tr>'
                . '<td colspan="3" style="padding:2px 7px;font-size:7px;color:#6C6258;text-align:right;">VAT (15%)</td>'
                . '<td width="18%" style="padding:2px 7px;font-size:7px;color:#1F1C17;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 675.00</td>'
                . '</tr>'
                . '<tr>'
                . '<td colspan="3" style="padding:5px 7px;background:#20303E;color:#F7F1EA;font-size:7px;font-weight:700;text-align:right;">Invoice Total</td>'
                . '<td width="18%" style="padding:5px 7px;background:#20303E;color:#D6A968;font-size:7px;font-weight:700;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 5,175.00</td>'
                . '</tr>'
                . '<tr>'
                . '<td colspan="3" style="padding:2px 7px 1px;font-size:7px;color:#6C6258;text-align:right;">Amount Paid</td>'
                . '<td width="18%" style="padding:2px 7px 1px;font-size:7px;color:#1E6C43;font-weight:700;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 1,000.00</td>'
                . '</tr>'
                . '<tr>'
                . '<td colspan="3" style="padding:1px 7px 4px;font-size:7px;color:#A63A3A;font-weight:700;text-align:right;">Balance Due</td>'
                . '<td width="18%" style="padding:1px 7px 4px;font-size:7px;color:#A63A3A;font-weight:700;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 4,175.00</td>'
                . '</tr>';
            $invoicePaymentHistorySection = '<div style="background:#FCFAF7;padding:9px 11px;border-top:2px solid #D5B37C;">'
                . '<p style="margin:0 0 4px;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#20303E;font-weight:700;">Payment History</p>'
                . '<table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0">'
                . '<tr>'
                . '<th style="padding:0 0 3px;text-align:left;font-size:6px;letter-spacing:0.7px;text-transform:uppercase;color:#7C6E5B;font-weight:700;">Date</th>'
                . '<th style="padding:0 0 3px;text-align:left;font-size:6px;letter-spacing:0.7px;text-transform:uppercase;color:#7C6E5B;font-weight:700;">Method</th>'
                . '<th style="padding:0 0 3px;text-align:right;font-size:6px;letter-spacing:0.7px;text-transform:uppercase;color:#7C6E5B;font-weight:700;">Amount</th>'
                . '</tr>'
                . '<tr>'
                . '<td style="padding:3px 0;border-top:1px solid #E6D9CC;font-size:6px;color:#6C6258;">' . date('j M Y', strtotime('-2 days')) . '</td>'
                . '<td style="padding:3px 0;border-top:1px solid #E6D9CC;font-size:6px;color:#6C6258;">Bank Transfer</td>'
                . '<td style="padding:3px 0;border-top:1px solid #E6D9CC;font-size:6px;color:#1F1C17;font-weight:600;text-align:right;">' . htmlspecialchars((string)getSetting('currency_symbol', 'MWK'), ENT_QUOTES, 'UTF-8') . ' 1,000.00</td>'
                . '</tr>'
                . '</table>'
                . '</div>';

            $invoiceBankRows = [];
            $invoiceBankName = trim((string)getSetting('bank_name', ''));
            $invoiceBankAccountName = trim((string)getSetting('bank_account_name', ''));
            $invoiceBankAccountNumber = trim((string)getSetting('bank_account_number', ''));
            $invoiceBankBranch = trim((string)getSetting('bank_branch', ''));
            if ($invoiceBankName !== '') {
                $invoiceBankRows[] = '<tr><td style="padding:1px 0;font-size:6px;color:#1F1C17;line-height:1.3;text-align:left;"><span style="color:#7A6F63;font-weight:700;">Bank:</span> <span style="font-weight:600;">' . htmlspecialchars($invoiceBankName, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            if ($invoiceBankAccountName !== '') {
                $invoiceBankRows[] = '<tr><td style="padding:1px 0;font-size:6px;color:#1F1C17;line-height:1.3;text-align:left;"><span style="color:#7A6F63;font-weight:700;">Account Name:</span> <span style="font-weight:600;">' . htmlspecialchars($invoiceBankAccountName, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            if ($invoiceBankAccountNumber !== '') {
                $invoiceBankRows[] = '<tr><td style="padding:1px 0;font-size:6px;color:#1F1C17;line-height:1.3;text-align:left;"><span style="color:#7A6F63;font-weight:700;">Account No.:</span> <span style="font-weight:600;">' . htmlspecialchars($invoiceBankAccountNumber, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            if ($invoiceBankBranch !== '') {
                $invoiceBankRows[] = '<tr><td style="padding:1px 0;font-size:6px;color:#1F1C17;line-height:1.3;text-align:left;"><span style="color:#7A6F63;font-weight:700;">Branch:</span> <span style="font-weight:600;">' . htmlspecialchars($invoiceBankBranch, ENT_QUOTES, 'UTF-8') . '</span></td></tr>';
            }
            $invoiceBankDetailsHtml = $invoiceBankRows !== []
                ? '<div style="background:#FCFAF7;padding:9px 11px;border-top:2px solid #D5B37C;text-align:left;">'
                . '<p style="margin:0 0 4px;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#20303E;font-weight:700;">Bank Details</p>'
                . '<table style="width:100%;border-collapse:collapse;" cellpadding="0" cellspacing="0">' . implode('', $invoiceBankRows) . '</table>'
                . '</div>'
                : '';

            $invoiceTermsText = trim((string)getSetting('invoice_terms', getSetting('payment_terms', '')));
            if ($invoiceTermsText !== '') {
                $invoiceTermsText = strtr($invoiceTermsText, [
                    '{{contact_email}}' => (string)(getEmailSetting('email_from_email') ?: getSetting('contact_email', 'reservations@example.com')),
                    '{{contact_phone}}' => (string)getSetting('phone_main', ''),
                    '{{site_name}}' => (string)getSetting('site_name', 'Hotel'),
                ]);
            }
            $invoiceTermsHtml = $invoiceTermsText !== ''
                ? '<div style="background:#FCFAF7;padding:9px 11px;border-top:2px solid #20303E;">'
                . '<p style="margin:0 0 4px;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#20303E;font-weight:700;">Invoice Terms</p>'
                . '<p style="margin:0;font-size:6px;line-height:1.4;color:#5F5343;">' . nl2br(htmlspecialchars($invoiceTermsText, ENT_QUOTES, 'UTF-8')) . '</p>'
                . '</div>'
                : '';
            $invoiceVatNumber = trim((string)getSetting('vat_number', ''));
            $invoiceVatNumberHtml = $invoiceVatNumber !== ''
                ? '<p style="margin:0 0 3px;font-size:10px;color:#6C6258;">VAT Reg: ' . htmlspecialchars($invoiceVatNumber, ENT_QUOTES, 'UTF-8') . '</p>'
                : '';

            $invoicePreviewHtml = strtr($ajaxHtmlBody, array_merge($ajaxVars, [
                '{{logo_html}}' => $invoiceLogoHtml,
                '{{invoice_number}}' => 'INV-2026-000001',
                '{{issued_date}}' => date('j F Y'),
                '{{status_text}}' => 'BALANCE DUE',
                '{{status_bg}}' => '#FCE8E6',
                '{{status_fg}}' => '#A63A3A',
                '{{room_icon_html}}' => $invoiceRoomIconHtml,
                '{{vat_number_html}}' => $invoiceVatNumberHtml,
                '{{charges_table_rows}}' => $invoiceChargesRows,
                '{{totals_rows}}' => $invoiceTotalsRows,
                '{{payment_history_section}}' => $invoicePaymentHistorySection,
                '{{bank_details}}' => $invoiceBankDetailsHtml,
                '{{invoice_terms}}' => $invoiceTermsHtml,
            ]));

            $ajaxFullHtml = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;padding:18px;background:#F4EFE8;}img{max-width:100%;}</style></head><body>' . $invoicePreviewHtml . '</body></html>';
            echo json_encode(['success' => true, 'full_html' => $ajaxFullHtml, 'subject' => $ajaxResSubject, 'text_body' => $ajaxResTextBody, 'html_body' => $invoicePreviewHtml, 'is_document' => true, 'has_unsaved_changes' => $hasUnsavedChanges]);
            exit;
        }

        if (in_array($ajaxKey, $booking_document_template_keys, true)) {
            $documentLogoUrl = function_exists('hotel_invoice_logo_src')
                ? hotel_invoice_logo_src()
                : '';
            $documentLogoHtml = $documentLogoUrl !== ''
                ? '<img src="' . htmlspecialchars($documentLogoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars((string)getSetting('site_name', 'Hotel'), ENT_QUOTES, 'UTF-8') . '" height="96" style="height:96px;width:auto;display:block;margin:0 auto;">'
                : '';
            $documentPreviewHtml = strtr($ajaxHtmlBody, array_merge($ajaxVars, [
                '{{logo_html}}' => $documentLogoHtml,
            ]));
            $ajaxFullHtml = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><style>body{margin:0;padding:18px;background:#F4EFE8;}img{max-width:100%;}</style></head><body>' . $documentPreviewHtml . '</body></html>';
            echo json_encode(['success' => true, 'full_html' => $ajaxFullHtml, 'subject' => $ajaxResSubject, 'text_body' => $ajaxResTextBody, 'html_body' => $documentPreviewHtml, 'is_document' => true, 'has_unsaved_changes' => $hasUnsavedChanges]);
            exit;
        }

        $ajaxFullHtml    = function_exists('wrapEmailTemplate')
            ? wrapEmailTemplate($ajaxResHtmlBody, $ajaxResSubject)
            : $ajaxResHtmlBody;

        echo json_encode(['success' => true, 'full_html' => $ajaxFullHtml, 'subject' => $ajaxResSubject, 'text_body' => $ajaxResTextBody, 'html_body' => $ajaxResHtmlBody, 'is_document' => false, 'has_unsaved_changes' => $hasUnsavedChanges]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// -----------------------------------------------------------------------
// AJAX: Send test email — returns JSON, exits immediately
// -----------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_test_email'], $_POST['_ajax_send_test'])) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $ajaxTestEmail   = trim((string)($_POST['test_email_address'] ?? ''));
        $ajaxTestSubject = trim((string)($_POST['test_subject'] ?? 'Test Email Preview'));
        $ajaxTestHtml    = trim((string)($_POST['test_html'] ?? ''));
        $ajaxTestText    = trim((string)($_POST['test_text_body'] ?? ''));
        $ajaxTestTemplateKey = trim((string)($_POST['test_template_key'] ?? ''));
        $ajaxIsDocument = in_array(strtolower(trim((string)($_POST['test_is_document'] ?? '0'))), ['1', 'true', 'yes'], true);
        if (!filter_var($ajaxTestEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        if (empty($ajaxTestHtml)) {
            throw new Exception('No preview HTML to send');
        }
        if (!$ajaxIsDocument && !function_exists('sendEmail')) {
            throw new Exception('Email sending not available');
        }
        // Emails sent log: a test (kind 'test', no booking), never under the real kind (owner 2026-11-12).
        $rhTestCtx = function_exists('rh_email_context') ? rh_email_context(['is_test' => 1,
            'test_of' => rh_email_kind_clean($ajaxTestTemplateKey) !== 'other' ? rh_email_kind_label(rh_email_kind_clean($ajaxTestTemplateKey)) : 'email template']) : null;
        if ($ajaxIsDocument) {
            if (!function_exists('sendEmailWithAttachments') || !function_exists('bookingRenderPdfFromHtml')) {
                throw new Exception('PDF attachment test sending is not available');
            }
            $attachmentStem = $ajaxTestTemplateKey !== '' ? $ajaxTestTemplateKey : 'document-preview';
            $attachmentStem = preg_replace('/[^A-Za-z0-9._-]+/', '-', $attachmentStem) ?: 'document-preview';
            $emailBody = '<p>Please find attached the PDF preview for <strong>' . htmlspecialchars($ajaxTestSubject, ENT_QUOTES, 'UTF-8') . '</strong>.</p>';
            $result = sendEmailWithAttachments(
                $ajaxTestEmail,
                $ajaxTestEmail,
                '[TEST] ' . $ajaxTestSubject,
                $emailBody,
                [[
                    'name' => $attachmentStem . '.pdf',
                    'content' => bookingRenderPdfFromHtml($ajaxTestHtml, $ajaxTestSubject),
                    'mime' => 'application/pdf',
                ]],
                'Attached is the PDF preview for ' . $ajaxTestSubject . '.'
            );
        } else {
            $result = sendEmail($ajaxTestEmail, $ajaxTestEmail, '[TEST] ' . $ajaxTestSubject, $ajaxTestHtml, $ajaxTestText);
        }
        if (!empty($result['success'])) {
            echo json_encode(['success' => true, 'message' => "Test email sent to {$ajaxTestEmail}"]);
        } else {
            throw new Exception($result['message'] ?? 'Unknown error');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    $rhTestCtx = null;
    exit;
}

// -----------------------------------------------------------------------
// Email Settings test button: sends ONE email through sendEmail() with the SAVED settings so the
// real From / Reply-To / hidden-copy path runs. Same gate as saving email settings (this page's
// permission + the CSRF guard above); never on the templates-only page (not in $rh_template_posts).
// AJAX (X-Requested-With) answers JSON; a plain POST shows the result on the page.
// -----------------------------------------------------------------------
$rh_email_test_result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email_settings_test']) && !$rh_templates_only) {
    $rhEtAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $rhEtTo = trim((string)($_POST['email_test_to'] ?? ''));
    if ($rhEtTo === '' || strlen($rhEtTo) > 254 || !filter_var($rhEtTo, FILTER_VALIDATE_EMAIL)) {
        $rhEtTo = '';
    }
    $rhEtSummary = rh_mail_settings_summary($rhEtTo);
    $rh_email_test_result = ['success' => false, 'preview' => false, 'message' => '', 'summary' => $rhEtSummary];
    if ($rhEtTo === '') {
        $rh_email_test_result['message'] = 'Enter a valid email address to send the test to.';
    } elseif (!function_exists('sendEmail')) {
        $rh_email_test_result['message'] = 'Email sending is not available.';
    } else {
        $rhEtRows = '';
        $rhEtText = '';
        foreach ($rhEtSummary as [$rhEtLabel, $rhEtValue]) {
            $rhEtRows .= '<tr><td style="padding:6px 12px 6px 0;vertical-align:top;font-weight:bold;">' . htmlspecialchars((string)$rhEtLabel, ENT_QUOTES, 'UTF-8')
                . '</td><td style="padding:6px 0;vertical-align:top;">' . htmlspecialchars((string)$rhEtValue, ENT_QUOTES, 'UTF-8') . '</td></tr>';
            $rhEtText .= $rhEtLabel . ': ' . $rhEtValue . "\n";
        }
        $rhEtHtml = '<p>This is a test of the saved Email Settings. If it arrived, the hotel can send email.</p>'
            . '<table role="presentation" style="border-collapse:collapse;">' . $rhEtRows . '</table>'
            . '<p>Reply to this email to check that replies reach the addresses above.</p>';
        // One try only (a dead server must not hold the page for 3 x the timeout); no SMTP debug output.
        $GLOBALS['rh_email_fast_fail'] = true;
        $GLOBALS['smtp_debug'] = 0;
        $rhTestCtx = function_exists('rh_email_context') ? rh_email_context(['is_test' => 1, 'test_of' => 'Email settings']) : null; // logged as a test
        try {
            $rhEtResult = sendEmail($rhEtTo, '', '[TEST] Email settings check', $rhEtHtml,
                "This is a test of the saved Email Settings. If it arrived, the hotel can send email.\n\n" . $rhEtText);
        } finally {
            $rhTestCtx = null;
            if (function_exists('rh_email_context_clear')) {
                rh_email_context_clear();
            }
        }
        unset($GLOBALS['rh_email_fast_fail']);
        if (!empty($rhEtResult['preview_url'])) {
            $rh_email_test_result['success'] = true;
            $rh_email_test_result['preview'] = true;
            $rh_email_test_result['message'] = 'Preview only is on and this computer is local, so the test was saved as a preview, not sent.';
        } elseif (!empty($rhEtResult['success'])) {
            $rh_email_test_result['success'] = true;
            $rh_email_test_result['message'] = 'Test email sent to ' . $rhEtTo . '. Check that inbox (and its spam folder).';
        } else {
            $rhEtErr = (string)($rhEtResult['message'] ?? 'Unknown error');
            $rhEtPass = (string)getEmailSetting('smtp_password', '');
            if ($rhEtPass !== '') {
                $rhEtErr = str_replace($rhEtPass, '***', $rhEtErr);
            }
            $rh_email_test_result['message'] = 'The test email was not sent. ' . $rhEtErr;
        }
    }
    if ($rhEtAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($rh_email_test_result['success']
            ? ['success' => true, 'preview' => $rh_email_test_result['preview'], 'message' => $rh_email_test_result['message'], 'summary' => $rhEtSummary]
            : ['success' => false, 'error' => $rh_email_test_result['message'], 'summary' => $rhEtSummary]);
        exit;
    }
    if ($rh_email_test_result['success']) {
        $message = htmlspecialchars($rh_email_test_result['message'], ENT_QUOTES, 'UTF-8');
    } else {
        $error = htmlspecialchars($rh_email_test_result['message'], ENT_QUOTES, 'UTF-8');
    }
}

// Handle setting updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    rh_settings_log_begin($user); // settings change log, second span: this block only (ended in finally)
    try {
        // Check which form was submitted
        if (isset($_POST['max_advance_booking_days'])) {
            // Booking settings form
            $max_advance_days = (int)($_POST['max_advance_booking_days'] ?? 30);

            // Validate input
            if ($max_advance_days < 1) {
                throw new Exception('Maximum advance booking days must be at least 1');
            }

            if ($max_advance_days > 365) {
                throw new Exception('Maximum advance booking days cannot exceed 365 (one year)');
            }

            // Update setting in database
            // updateSetting() upserts (a plain UPDATE silently did nothing when the row was missing)
            if (!updateSetting('max_advance_booking_days', (string)$max_advance_days)) {
                throw new Exception('Failed to save the advance booking limit');
            }

            // Clear the setting cache (both in-memory and file cache)
            global $_SITE_SETTINGS;
            if (isset($_SITE_SETTINGS['max_advance_booking_days'])) {
                unset($_SITE_SETTINGS['max_advance_booking_days']);
            }
            // Clear the file cache
            deleteCache("setting_max_advance_booking_days");

            $message = "Maximum advance booking days updated to {$max_advance_days} days successfully!";
        } elseif (isset($_POST['tourism_levy_settings']) && !hasPermission((int)($user['id'] ?? 0), 'finance_settings')) {
            $error = 'You do not have permission to change the tourism levy.'; // same gate as VAT (finance_settings)
        } elseif (isset($_POST['tourism_levy_settings'])) {
            // Tourism levy settings
            $tourism_levy_enabled = isset($_POST['tourism_levy_enabled']) ? '1' : '0';
            $tourism_levy_percent = round((float)($_POST['tourism_levy_percent'] ?? 0), 2);

            // Validate input
            if ($tourism_levy_percent < 0) {
                throw new Exception('Tourism levy percent cannot be negative');
            }

            if ($tourism_levy_percent > 100) {
                throw new Exception('Tourism levy percent cannot exceed 100%');
            }

            // Update settings in database
            updateSetting('tourism_levy_enabled', $tourism_levy_enabled);
            updateSetting('tourism_levy_percent', $tourism_levy_percent);

            // Clear the setting cache
            global $_SITE_SETTINGS;
            if (isset($_SITE_SETTINGS['tourism_levy_enabled'])) {
                unset($_SITE_SETTINGS['tourism_levy_enabled']);
            }
            if (isset($_SITE_SETTINGS['tourism_levy_percent'])) {
                unset($_SITE_SETTINGS['tourism_levy_percent']);
            }
            // Clear the file cache
            deleteCache("setting_tourism_levy_enabled");
            deleteCache("setting_tourism_levy_percent");

            $message = $tourism_levy_enabled === '1'
                ? "Tourism levy enabled at {$tourism_levy_percent}% successfully!"
                : "Tourism levy disabled successfully!";
        } elseif (isset($_POST['booking_notification_settings'])) {
            $booking_notification_email = trim($_POST['booking_notification_email'] ?? '');
            $booking_notification_cc_emails = trim($_POST['booking_notification_cc_emails'] ?? '');
            $booking_notification_cc_emails = implode(', ', array_filter(array_map('trim', preg_split('/[,;\s]+/', $booking_notification_cc_emails))));
            if (mb_strlen($booking_notification_cc_emails) > 1000) {
                throw new Exception('Too many CC addresses (1000 characters maximum)');
            }

            if (!empty($booking_notification_email) && !filter_var($booking_notification_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Booking notification email address is invalid');
            }

            if (!empty($booking_notification_cc_emails)) {
                $ccList = array_filter(array_map('trim', explode(',', $booking_notification_cc_emails)));
                foreach ($ccList as $ccEmail) {
                    if (!filter_var($ccEmail, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('One or more booking CC email addresses are invalid');
                    }
                }
            }

            $savedPrimary = updateSetting('booking_notification_email', $booking_notification_email);
            $savedCc = updateSetting('booking_notification_cc_emails', $booking_notification_cc_emails);
            if (!$savedPrimary || !$savedCc) {
                throw new Exception('Failed to save booking notification email settings');
            }

            // Guest communication lifecycle emails (pre-arrival reminder / post-stay review request)
            $prearrival_enabled = isset($_POST['booking_prearrival_reminder_enabled']) ? '1' : '0';
            $prearrival_days = (int)($_POST['booking_prearrival_reminder_days'] ?? 1);
            if ($prearrival_days < 1) $prearrival_days = 1;
            if ($prearrival_days > 14) $prearrival_days = 14;

            $poststay_enabled = isset($_POST['booking_poststay_review_enabled']) ? '1' : '0';
            $poststay_days = (int)($_POST['booking_poststay_review_days'] ?? 1);
            if ($poststay_days < 0) $poststay_days = 0;
            if ($poststay_days > 14) $poststay_days = 14;

            updateSetting('booking_prearrival_reminder_enabled', $prearrival_enabled);
            updateSetting('booking_prearrival_reminder_days', (string)$prearrival_days);
            updateSetting('booking_poststay_review_enabled', $poststay_enabled);
            updateSetting('booking_poststay_review_days', (string)$poststay_days);

            $message = "Booking notification email updated successfully!";
        } elseif (isset($_POST['service_channel_settings'])) {
            if (!$canSiteSwitches) {
                throw new Exception($rh_site_switch_refusal); // owner 2026-11-11: administrators only
            }
            $conference_enabled = isset($_POST['conference_system_enabled']) ? '1' : '0';
            $gym_enabled = isset($_POST['gym_system_enabled']) ? '1' : '0';
            $restaurant_enabled = isset($_POST['restaurant_system_enabled']) ? '1' : '0';

            $conference_email = trim((string)($_POST['conference_email'] ?? ''));
            $gym_email = trim((string)($_POST['gym_email'] ?? ''));
            $email_restaurant = trim((string)($_POST['email_restaurant'] ?? ''));

            if ($conference_email !== '' && !filter_var($conference_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Conference notification email is invalid');
            }
            if ($gym_email !== '' && !filter_var($gym_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Gym notification email is invalid');
            }
            if ($email_restaurant !== '' && !filter_var($email_restaurant, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Restaurant notification email is invalid');
            }

            updateSetting('conference_system_enabled', $conference_enabled);
            updateSetting('gym_system_enabled', $gym_enabled);
            updateSetting('restaurant_system_enabled', $restaurant_enabled);

            updateSetting('conference_email', $conference_email);
            updateSetting('gym_email', $gym_email);
            // Restaurant page shows the reservations email while this was never saved; a blank box
            // must not replace that with nothing.
            if ($email_restaurant !== '' || getSetting('email_restaurant', '__rh_unset__') !== '__rh_unset__') {
                updateSetting('email_restaurant', $email_restaurant);
            }

            $message = 'Service channels and notification emails updated successfully!';
        } elseif (isset($_POST['save_pwa_settings'])) {
            $dismissDays = (int)($_POST['pwa_install_dismiss_days'] ?? 14);
            if ($dismissDays < 1) {
                throw new Exception('Dismiss period must be at least 1 day.');
            }
            if ($dismissDays > 365) {
                throw new Exception('Dismiss period cannot exceed 365 days.');
            }
            updateSetting('pwa_install_dismiss_days', (string)$dismissDays);
            deleteCache('setting_pwa_install_dismiss_days');
            $message = 'PWA install banner settings saved — staff will be prompted every ' . $dismissDays . ' day(s) after dismissing.';
        } elseif (isset($_POST['booking_email_template_preview'])) {
            $templateKey = trim((string)($_POST['booking_email_template_preview'] ?? ''));

            $templateDefs = $booking_template_defs_master;

            if (!isset($templateDefs[$templateKey])) {
                throw new Exception('Invalid template selected for preview');
            }

            $templateName = $templateDefs[$templateKey];
            $subjectRaw  = trim((string)($_POST[$templateKey . '_subject']   ?? ''));
            $htmlBodyRaw = trim((string)($_POST[$templateKey . '_html_body'] ?? ''));
            $textBodyRaw = trim((string)($_POST[$templateKey . '_text_body'] ?? ''));

            // Fall back to DB / seeded defaults if the textarea was empty
            if ($subjectRaw === '' || $htmlBodyRaw === '') {
                $existing = function_exists('getBookingEmailTemplateConfig')
                    ? getBookingEmailTemplateConfig($templateKey, [])
                    : [];
                if (!empty($existing['subject'])) {
                    $subjectRaw  = $existing['subject'];
                }
                if (!empty($existing['html_body'])) {
                    $htmlBodyRaw = $existing['html_body'];
                }
                if ($textBodyRaw === '' && !empty($existing['text_body'])) {
                    $textBodyRaw = $existing['text_body'];
                }
            }

            if ($subjectRaw === '' || $htmlBodyRaw === '') {
                throw new Exception('This template has no content yet — save it first, or type content into the HTML body field.');
            }

            $currencySymbol = (string)getSetting('currency_symbol', 'MWK');
            $previewVars = [
                '{{site_name}}'                => (string)getSetting('site_name', 'Hotel'),
                '{{site_url}}'                 => (string)getSetting('site_url', ''),
                '{{booking_reference}}'        => 'RBH-2026-PREVIEW-001',
                '{{inquiry_reference}}'        => 'CONF-2026-PREVIEW-001',
                '{{guest_name}}'               => 'Jane Doe',
                '{{guest_email}}'              => 'jane.doe@example.com',
                '{{guest_phone}}'              => '+27 82 555 0000',
                '{{recipient_name}}'           => 'Jane Doe',
                '{{contact_person}}'           => 'Jane Doe',
                '{{company_name}}'             => 'Mwai Consulting Ltd',
                '{{room_name}}'                => 'Deluxe Ocean Suite',
                '{{booked_rate}}'              => 'Deluxe Ocean Suite - ' . $currencySymbol . '356,000 x 3 nights (a night, incl. VAT and tourism levy)',
                '{{conference_room}}'          => 'Baobab Conference Suite',
                '{{event_title}}'              => 'Sunset Jazz Night',
                '{{event_location}}'           => 'Beachfront Pavilion',
                '{{check_in_date_formatted}}'  => date('F j, Y', strtotime('+14 days')),
                '{{check_out_date_formatted}}' => date('F j, Y', strtotime('+16 days')),
                '{{check_in_date}}'            => date('l, F j, Y', strtotime('+14 days')),
                '{{check_out_date}}'           => date('l, F j, Y', strtotime('+16 days')),
                '{{event_date}}'               => date('l, F j, Y', strtotime('+30 days')),
                '{{event_time}}'               => '09:00 - 17:00',
                '{{number_of_nights}}'         => '2',
                '{{nights}}'                   => '2',
                '{{number_of_guests}}'         => '2',
                '{{guests}}'                   => '2 adults',
                '{{adult_guests}}'             => '2',
                '{{child_guests}}'             => '0',
                '{{attendees}}'                => '60',
                '{{attendee_count}}'           => '4',
                '{{total_amount_formatted}}'   => number_format(4500, 2),
                '{{total_amount}}'             => $currencySymbol . number_format(4500, 2),
                '{{currency_symbol}}'          => $currencySymbol,
                '{{contact_email}}'            => (string)(getEmailSetting('email_from_email') ?: getSetting('contact_email', 'reservations@example.com')),
                '{{contact_phone}}'            => (string)getSetting('phone_main', ''),
                '{{phone_main}}'               => (string)getSetting('phone_main', ''),
                '{{payment_policy}}'           => 'Full payment is due 48 hours before check-in.',
                '{{check_in_time}}'            => (string)getSetting('check_in_time', '2:00 PM'),
                '{{check_out_time}}'           => (string)getSetting('check_out_time', '11:00 AM'),
                '{{cancellation_reason}}'      => 'Requested by guest.',
                '{{special_requests}}'         => 'Late check-in preferred.',
                '{{tentative_expires_at_formatted}}' => date('F j, Y g:i A', strtotime('+2 days')),
                // Quotation-specific
                '{{quotation_reference}}'      => 'QT-RBH-2026-PREVIEW-001',
                '{{quote_reference}}'          => 'QT-RBH-2026-PREVIEW-001',
                '{{valid_until}}'              => date('F j, Y', strtotime('+7 days')),
                '{{quotation_notes}}'          => 'Please reply by the valid-until date to secure your reservation.',
                '{{rate_per_night}}'           => $currencySymbol . number_format(2250, 2),
                '{{room_subtotal}}'            => $currencySymbol . number_format(4500, 2),
                '{{vat_amount}}'               => $currencySymbol . number_format(675, 2),
                '{{vat_rate}}'                 => '15',
                '{{child_supplement}}'         => $currencySymbol . '0',
                '{{deposit_amount}}'           => $currencySymbol . number_format(1000, 2),
                '{{balance_due}}'              => $currencySymbol . number_format(3500, 2),
                '{{amount_due}}'               => number_format(4175, 2),
                '{{due_date}}'                 => date('F j, Y', strtotime('-3 days')),
                '{{days_overdue}}'             => '3',
                '{{account_reference}}'        => 'BK-PREVIEW-001',
                '{{days_left}}'                => '2 days from now',
                '{{quote_total}}'              => number_format(4500, 2),
                '{{pay_instructions}}'         => '<p style="margin:0 0 8px;"><strong>How to pay:</strong> bank transfer or at reception. Please quote your account reference.</p>',
                '{{invoice_number}}'           => 'INV-2026-000001',
                '{{issued_date}}'              => date('j F Y'),
                '{{status_text}}'              => 'BALANCE DUE',
                '{{client_email}}'             => 'events@example.com',
                '{{client_phone}}'             => '+27 82 444 2211',
                '{{event_type}}'               => 'Corporate Seminar',
                '{{rate_per_attendee}}'        => $currencySymbol . number_format(750, 2),
                '{{payment_reference}}'        => 'PAY-2026-PREVIEW-001',
                '{{payment_date}}'             => date('d M Y'),
                '{{payment_method}}'           => 'Bank Transfer',
                '{{payment_type}}'             => 'Full Payment',
                '{{payment_status}}'           => 'Completed',
                '{{payment_amount}}'           => $currencySymbol . number_format(4500, 2),
                '{{receipt_number}}'           => 'RCP-2026-000042',
                '{{booking_type}}'             => 'Restaurant',
                '{{description}}'              => 'Restaurant order preview for table service payment.',
                '{{bank_details_html}}'        => '<div style="background:#FCFAF7;padding:7px 10px;border-top:2px solid #D5B37C;"><p style="margin:0 0 4px;font-size:6px;letter-spacing:1px;text-transform:uppercase;color:#20303E;font-weight:700;">Bank Details</p><p style="margin:0;font-size:6px;color:#1E2430;">Bank: Preview Bank<br>Account No.: 00123456789</p></div>',
                '{{receipt_terms}}'            => '<p style="margin:0;font-size:6px;line-height:1.5;color:#5F655F;">Thank you for your payment. Please retain this receipt for your records.</p>',
                '{{credit_note_number}}'       => 'CN-RBH-2026-001',
                '{{amount}}'                   => $currencySymbol . number_format(1200, 2),
                '{{balance}}'                  => $currencySymbol . number_format(850, 2),
                '{{amount_used}}'              => $currencySymbol . number_format(350, 2),
                '{{reason}}'                   => 'Overpayment adjustment',
                '{{reason_notes}}'             => 'Issued after reservation amount was corrected.',
                '{{expires_at}}'               => date('F j, Y', strtotime('+90 days')),
                '{{hotel_phone}}'              => (string)getSetting('phone_main', ''),
                '{{hotel_address}}'            => (string)getSetting('hotel_address', getSetting('address', '')),
                '{{address}}'                  => (string)getSetting('hotel_address', getSetting('address', '')),
                '{{logo_html}}'                => (function () use ($currencySymbol): string {
                    $url      = function_exists('getHotelLogoUrl') ? getHotelLogoUrl() : '';
                    $siteName = (string)getSetting('site_name', 'Hotel');
                    return $url !== '' ? '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') . '" style="max-width:160px;height:auto;display:block;margin:0 auto;">' : '';
                })(),
                '{{subtotal_amount}}'          => $currencySymbol . number_format(4500, 2),
                '{{vat_number}}'               => (string)getSetting('vat_number', ''),
                '{{vat_number_html}}'          => (function (): string {
                    $n = (string)getSetting('vat_number', '');
                    return $n !== '' ? '<p style="margin:8px 0 0;font-size:11px;color:#9b8f7e;text-align:center;">VAT Reg. No.: ' . htmlspecialchars($n, ENT_QUOTES, 'UTF-8') . '</p>' : '';
                })(),
                '{{levy_rate}}'                => '1.0',
                '{{levy_amount}}'              => $currencySymbol . number_format(45, 2),
            ];

            $resolvedSubject  = strtr($subjectRaw,  $previewVars);
            $resolvedHtmlBody = strtr($htmlBodyRaw, $previewVars);
            $resolvedTextBody = strtr($textBodyRaw, $previewVars);

            // Wrap in the hotel email wrapper so preview matches actual sent email
            $fullHtml = function_exists('wrapEmailTemplate')
                ? wrapEmailTemplate($resolvedHtmlBody, $resolvedSubject)
                : $resolvedHtmlBody;

            $template_preview = [
                'template_key'  => $templateKey,
                'template_name' => $templateName,
                'subject'       => $resolvedSubject,
                'html_body'     => $resolvedHtmlBody,
                'full_html'     => $fullHtml,
                'text_body'     => $resolvedTextBody,
                'variables'     => $previewVars,
            ];

            $message = 'Preview generated — scroll down to see how the email will look.';
        } elseif (isset($_POST['send_test_email'])) {
            $testEmail = trim((string)($_POST['test_email_address'] ?? ''));
            if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Please enter a valid email address to send the test to.');
            }
            $testSubject = trim((string)($_POST['test_subject'] ?? 'Test Email Preview'));
            $testHtml    = trim((string)($_POST['test_html']    ?? ''));
            if (empty($testHtml)) {
                throw new Exception('No preview content to send. Please generate a preview first.');
            }
            if (!function_exists('sendEmail')) {
                throw new Exception('Email sending is not available on this page.');
            }
            $rhTestKey = trim((string)($_POST['test_template_key'] ?? ''));
            $rhTestCtx = function_exists('rh_email_context') ? rh_email_context(['is_test' => 1, // logged as a test, never under the real kind
                'test_of' => rh_email_kind_clean($rhTestKey) !== 'other' ? rh_email_kind_label(rh_email_kind_clean($rhTestKey)) : 'email template']) : null;
            try {
                $result = sendEmail($testEmail, $testEmail, '[TEST] ' . $testSubject, $testHtml);
            } finally {
                $rhTestCtx = null;
                if (function_exists('rh_email_context_clear')) {
                    rh_email_context_clear();
                }
            }
            if (!empty($result['success'])) {
                $message = "Test email sent to {$testEmail} successfully!";
            } else {
                throw new Exception('Failed to send test email: ' . ($result['message'] ?? 'Unknown error'));
            }
        } elseif (isset($_POST['reset_all_booking_templates_to_defaults'])) {
            if (!$canEditTemplates) {
                throw new Exception('You do not have permission to edit message templates.');
            }
            if (!function_exists('resetBookingEmailTemplatesToDefaults')) {
                throw new Exception('Booking template reset is not available');
            }

            $resetResult = resetBookingEmailTemplatesToDefaults(true);
            if (empty($resetResult['success'])) {
                throw new Exception((string)($resetResult['message'] ?? 'Failed to reset booking templates.'));
            }

            $updated = (int)($resetResult['updated'] ?? 0);
            $message = "All booking email and PDF templates were reset to the default design ({$updated} templates).";
        } elseif (isset($_POST['save_receipt_whatsapp'])) {
            // The WhatsApp wording sent with a receipt (admin/receipts.php reads this same setting).
            if (!$canEditTemplates) {
                throw new Exception('You do not have permission to edit message templates.');
            }
            $receiptWhatsapp = trim((string)($_POST['receipt_whatsapp_template'] ?? ''));
            if ($receiptWhatsapp === '') {
                throw new Exception('The receipt WhatsApp message cannot be empty.');
            }
            if (mb_strlen($receiptWhatsapp) > 2000) {
                throw new Exception('The receipt WhatsApp message is too long (max 2000 characters).');
            }
            $rhWaOld = rh_settings_log_old($pdo, 'site_settings', 'receipt_whatsapp_template'); // settings change log
            $stmtWa = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'finance') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
            $stmtWa->execute(['receipt_whatsapp_template', $receiptWhatsapp]);
            if ($rhWaOld !== null) {
                rh_settings_log_record('site_settings', 'receipt_whatsapp_template', $rhWaOld['value'], $receiptWhatsapp);
            }
            if (function_exists('deleteCache')) {
                deleteCache('setting_receipt_whatsapp_template');
            }
            if (function_exists('rh_log_event')) {
                rh_log_event('receipts', 'info', 'Receipt WhatsApp message updated', ['by' => $user['username'] ?? null]);
            }
            $message = 'Receipt WhatsApp message saved.';
        } elseif (isset($_POST['booking_email_templates'])) {
            if (!$canEditTemplates) {
                throw new Exception('You do not have permission to edit message templates.');
            }
            if (!function_exists('upsertBookingEmailTemplateConfig')) {
                throw new Exception('Booking template storage is not available');
            }

            $templateDefs = $booking_template_defs_master;

            // Validate every template first so one bad template never leaves the set half-saved.
            foreach ($templateDefs as $templateKey => $templateName) {
                $chkSubject = trim((string)($_POST[$templateKey . '_subject'] ?? ''));
                $chkHtml = trim((string)($_POST[$templateKey . '_html_body'] ?? ''));
                if ($chkSubject === '' || $chkHtml === '') {
                    throw new Exception("{$templateName}: subject and HTML body are required");
                }
                if (mb_strlen($chkSubject) > 255) {
                    throw new Exception("{$templateName}: subject is too long (max 255 characters)");
                }
            }

            foreach ($templateDefs as $templateKey => $templateName) {
                $subject = trim((string)($_POST[$templateKey . '_subject'] ?? ''));
                $htmlBody = trim((string)($_POST[$templateKey . '_html_body'] ?? ''));
                $textBody = trim((string)($_POST[$templateKey . '_text_body'] ?? ''));
                $isActive = isset($_POST[$templateKey . '_is_active']) ? 1 : 0;

                if (!upsertBookingEmailTemplateConfig($templateKey, $templateName, $subject, $htmlBody, $textBody, $isActive)) {
                    throw new Exception("Failed to save template: {$templateName}");
                }
            }

            $message = "Booking email templates updated successfully!";
        } elseif (isset($_POST['email_settings'])) {
            // Email settings form
            $email_settings = [
                'smtp_host' => trim((string)($_POST['smtp_host'] ?? '')),
                'smtp_port' => trim((string)($_POST['smtp_port'] ?? '')),
                'smtp_username' => trim((string)($_POST['smtp_username'] ?? '')),
                'smtp_password' => $_POST['smtp_password'] ?? '',
                'smtp_secure' => strtolower(trim((string)($_POST['smtp_secure'] ?? 'ssl'))),
                'email_from_name' => trim((string)($_POST['email_from_name'] ?? '')),
                'email_from_email' => trim((string)($_POST['email_from_email'] ?? '')),
                'email_admin_email' => trim((string)($_POST['email_admin_email'] ?? '')),
                'email_bcc_admin' => isset($_POST['email_bcc_admin']) ? '1' : '0',
                'email_development_mode' => isset($_POST['email_development_mode']) ? '1' : '0',
                'email_log_enabled' => isset($_POST['email_log_enabled']) ? '1' : '0',
                'email_preview_enabled' => isset($_POST['email_preview_enabled']) ? '1' : '0',
                'invoice_recipients' => trim((string)($_POST['invoice_recipients'] ?? '')),
                'email_reply_to_backup' => trim((string)($_POST['email_reply_to_backup'] ?? '')),
                'email_admin_backup' => trim((string)($_POST['email_admin_backup'] ?? '')),
            ];
            // Owner 2026-11-11: the email server part needs email_server_settings (super admin only). Without it those
            // fields render read-only (so they are not posted): they are neither checked nor saved here, and every other
            // field (sender name, reply-to, staff copy, invoice copies) saves as before.
            if (!$canEmailServer) {
                foreach (['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_secure', 'email_bcc_admin',
                          'email_development_mode', 'email_log_enabled', 'email_preview_enabled'] as $rhServerKey) {
                    unset($email_settings[$rhServerKey]);
                }
            }

            if (isset($email_settings['smtp_secure']) && !in_array($email_settings['smtp_secure'], ['ssl', 'tls', ''], true)) {
                $email_settings['smtp_secure'] = 'ssl';
            }

            // Validate required fields
            $required_fields = $canEmailServer ? ['smtp_host', 'smtp_port', 'smtp_username', 'email_from_name'] : ['email_from_name'];
            foreach ($required_fields as $field) {
                if (empty($email_settings[$field])) {
                    throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
                }
            }

            // Validate port
            if ($canEmailServer && (!is_numeric($email_settings['smtp_port']) || $email_settings['smtp_port'] < 1 || $email_settings['smtp_port'] > 65535)) {
                throw new Exception('SMTP port must be a valid port number (1-65535)');
            }

            if (preg_match('/[
]/', $email_settings['email_from_name']) || mb_strlen($email_settings['email_from_name']) > 100) {
                throw new Exception('Sender name must be a single line of at most 100 characters');
            }
            if ($canEmailServer && !preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $email_settings['smtp_host'])) {
                throw new Exception('SMTP host must be a host name such as mail.example.com');
            }

            // Validate emails
            if ($email_settings['email_from_email'] !== '' && !filter_var($email_settings['email_from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Reply-to and contact email address is invalid');
            }

            if (!empty($email_settings['email_admin_email']) && !filter_var($email_settings['email_admin_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Staff copy email address is invalid');
            }
            if ($email_settings['invoice_recipients'] !== '') {
                $invList = [];
                foreach (array_filter(array_map('trim', preg_split('/[,;\s]+/', $email_settings['invoice_recipients']))) as $invRcpt) {
                    if (!filter_var($invRcpt, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception('Invoice recipients must be valid email addresses separated by commas');
                    }
                    if (!isset($invList[strtolower($invRcpt)])) {
                        $invList[strtolower($invRcpt)] = $invRcpt;
                    }
                }
                // Consumers split on commas only, so store a clean comma-separated list.
                $email_settings['invoice_recipients'] = implode(', ', array_values($invList));
                if (mb_strlen($email_settings['invoice_recipients']) > 1000) {
                    throw new Exception('Too many invoice recipients (1000 characters maximum)');
                }
            }
            // Backup addresses (owner 2026-11-09): optional comma lists, at most 5 each, stored as "a, b".
            foreach (['email_reply_to_backup' => 'Backup reply-to emails', 'email_admin_backup' => 'Backup staff copy emails'] as $bkKey => $bkLabel) {
                if ($email_settings[$bkKey] === '') {
                    continue;
                }
                $bkList = [];
                foreach (array_filter(array_map('trim', preg_split('/[,;\s]+/', $email_settings[$bkKey]))) as $bkRcpt) {
                    if (!filter_var($bkRcpt, FILTER_VALIDATE_EMAIL)) {
                        throw new Exception($bkLabel . ' must be valid email addresses separated by commas');
                    }
                    if (!isset($bkList[strtolower($bkRcpt)])) {
                        $bkList[strtolower($bkRcpt)] = $bkRcpt;
                    }
                }
                if (count($bkList) > 5) {
                    throw new Exception($bkLabel . ': at most 5 addresses');
                }
                $email_settings[$bkKey] = implode(', ', array_values($bkList));
            }

            // Update email settings in database
            foreach ($email_settings as $key => $value) {
                if ($key === 'smtp_password' && empty($value)) {
                    // Keep existing encrypted password if field is left blank intentionally.
                    continue;
                }

                $is_encrypted = ($key === 'smtp_password' && !empty($value));
                if (!updateEmailSetting($key, $value, '', $is_encrypted)) {
                    throw new Exception('Failed to save SMTP/email setting: ' . $key);
                }
            }

            // Clear email cache so changes take effect immediately
            require_once __DIR__ . '/../config/cache.php';
            clearEmailCache();

            $message = "Email settings updated successfully!";
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    } finally {
        rh_settings_log_end();
    }
}

// Get current setting
$current_max_days = (int)getSetting('max_advance_booking_days', 30);
$current_booking_notification_email = getSetting('booking_notification_email', '');
$current_booking_notification_cc_emails = getSetting('booking_notification_cc_emails', '');
$current_prearrival_reminder_enabled = getSetting('booking_prearrival_reminder_enabled', '0') === '1';
$current_prearrival_reminder_days = (int)getSetting('booking_prearrival_reminder_days', '1');
$current_poststay_review_enabled = getSetting('booking_poststay_review_enabled', '0') === '1';
$current_poststay_review_days = (int)getSetting('booking_poststay_review_days', '1');

$current_conference_system_enabled = getSetting('conference_system_enabled', '1') === '1';
$current_gym_system_enabled = getSetting('gym_system_enabled', '1') === '1';
$current_restaurant_system_enabled = getSetting('restaurant_system_enabled', '1') === '1';

// Boxes show the saved value only; where emails really go comes from the same resolvers the
// sending code uses (includes/email-identity.php), shown under each box.
$current_conference_email = getSetting('conference_email', '');
$current_gym_email = getSetting('gym_email', '');
$current_restaurant_email = getSetting('email_restaurant', '');

/** "Currently goes to" line under an email box (escaped HTML). */
$rh_route_line = static function (array $route, string $saved, string $verb = 'Currently goes to', string $none = 'nobody'): string {
    $email = (string)($route['email'] ?? '');
    if ($email === '') {
        return '<p class="help-text"><i class="fas fa-triangle-exclamation"></i> ' . htmlspecialchars($verb) . ': ' . htmlspecialchars($none) . '. No address is set here or in the fallbacks.</p>';
    }
    $note = '';
    if (rh_ei_valid_email($saved) === '') {
        $note = ' (box blank, using ' . htmlspecialchars((string)($route['source'] ?? '')) . ')';
    }
    return '<p class="help-text"><i class="fas fa-envelope"></i> ' . htmlspecialchars($verb) . ': <strong>' . htmlspecialchars($email) . '</strong>' . $note . '</p>';
};

$booking_template_defs = $booking_template_defs_master;

$booking_templates = [];
foreach ($booking_template_defs as $template_key => $template_name) {
    $booking_templates[$template_key] = function_exists('getBookingEmailTemplateConfig')
        ? getBookingEmailTemplateConfig($template_key, [
            'template_key' => $template_key,
            'template_name' => $template_name,
            'subject' => '',
            'html_body' => '',
            'text_body' => '',
            'is_active' => 1
        ])
        : [
            'template_key' => $template_key,
            'template_name' => $template_name,
            'subject' => '',
            'html_body' => '',
            'text_body' => '',
            'is_active' => 1
        ];
}

// Built-in defaults for the "Load Default" button (canonical source from config/email.php)
$tplDefaults = [];
$canonicalTemplateDefaults = function_exists('hotel_booking_template_defaults_map')
    ? hotel_booking_template_defaults_map()
    : [];
foreach ($canonicalTemplateDefaults as $templateKey => $templateDefaults) {
    $tplDefaults[$templateKey] = [
        'subject' => (string)($templateDefaults['subject'] ?? ''),
        'html' => (string)($templateDefaults['html'] ?? ''),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $rh_templates_only ? 'Email &amp; PDF Templates' : ($mod_bookings ? 'Hotel Settings' : 'Business Settings'); ?> - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-booking-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-booking-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1><?php echo $rh_templates_only ? 'Email &amp; PDF Templates' : ($mod_bookings ? 'Hotel Settings' : 'Business Settings'); ?></h1>
                </div>
            </div>
            <div class="rh-page-head__actions">
                <a href="dashboard.php" class="back-link">
                    <i class="fas fa-arrow-left"></i> Back to Dashboard
                </a>
            </div>
        </header>

        <?php if (!$rh_templates_only): ?>
        <?php
        /* Settings cards in page order (owner 2026-11-14): anchor => [group, Jump-to label, search words, shown].
           Search words = the card title + the words in admin/api/global-search.php "Hotel Settings sections";
           keep the two lists in step. hotel-details and review-sources carry the same words in their
           includes (admin/includes/hotel-details-settings.php, review-sources-settings.php).
           Any new settings card joins a group here and gets search words. */
        $bsGroups = [
            'web' => 'Website & booking',
            'rooms' => 'Rooms & rates rules',
            'money' => 'Money & documents',
            'email' => 'Email & notifications',
            'modules' => 'Modules & app',
        ];
        $bsCards = [
            'maintenance' => ['web', 'Maintenance', 'Frontend maintenance mode maintenance offline website down', true],
            'booking-status' => ['web', 'Booking status', 'Booking system on/off booking enabled disabled online booking redirect', $mod_bookings],
            'hotel-details' => ['web', 'Hotel details', 'Hotel details & policies hotel name address phone check-in check-out time bank details policy vat', $mod_bookings],
            'advance-booking' => ['rooms', 'Advance booking', 'Advance booking window advance days max booking ahead', $mod_bookings],
            'tentative' => ['rooms', 'Tentative', 'Tentative bookings tentative hold provisional expiry', $mod_bookings],
            'booking-references' => ['rooms', 'References', 'Booking reference prefix reference prefix booking number', $mod_bookings],
            'cancellation' => ['money', 'Refunds', 'Refunds & cancellations refund cancel cancellation policy credit note', $mod_bookings],
            'tourism-levy' => ['money', 'Tourism levy', 'Tourism levy / city tax tourism levy city tax', $mod_bookings],
            'document-branding' => ['money', 'Branding', 'Document branding invoice pdf logo branding colours receipt', $mod_bookings],
            'email-config' => ['email', 'Email', 'Email server (SMTP) email smtp mail server password sender', true],
            'notification-email' => ['email', 'Notifications', 'Booking notification emails notification email alerts pre-arrival post-stay review', $mod_bookings],
            'service-modules' => ['modules', 'Service modules', 'Service modules & notification emails conference gym restaurant notification module', true],
            'review-sources' => ['modules', 'Review sources', 'Review sources (web importer) reviews google places brave api key import web', $mod_website_cms],
            'pwa-settings' => ['modules', 'PWA', 'Install app banner (PWA) pwa install app banner', true],
        ];
        $bsAttr = static function (string $anchor) use ($bsCards): string {
            return ' data-bs-group="' . htmlspecialchars($bsCards[$anchor][0], ENT_QUOTES, 'UTF-8') . '"'
                . ' data-bs-keywords="' . htmlspecialchars($bsCards[$anchor][2], ENT_QUOTES, 'UTF-8') . '"';
        };
        $bsGroupsShown = [];
        foreach ($bsCards as $bsCard) {
            if ($bsCard[3]) {
                $bsGroupsShown[$bsCard[0]] = true;
            }
        }
        ?>
        <div class="filter-bar bs-tools" role="search" aria-label="Find a setting">
            <label class="bs-tools__field bs-tools__field--search" for="bs-search">
                <span>Search settings</span>
                <input type="search" id="bs-search" placeholder="e.g. levy, SMTP, logo" autocomplete="off" aria-describedby="bs-count">
            </label>
            <label class="bs-tools__field bs-tools__field--group" for="bs-group-filter">
                <span>Group</span>
                <select id="bs-group-filter">
                    <option value="">All groups</option>
                    <?php foreach ($bsGroups as $bsKey => $bsName): if (empty($bsGroupsShown[$bsKey])) { continue; } ?>
                    <option value="<?php echo htmlspecialchars($bsKey, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bsName, ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <p class="bs-tools__count" id="bs-count" role="status" aria-live="polite"></p>
        </div>
        <nav class="bs-jump" aria-label="Settings sections">
            <span class="bs-jump__label">Jump to</span>
            <?php foreach ($bsCards as $bsAnchor => $bsCard): if (!$bsCard[3]) { continue; } ?>
            <a href="#<?php echo htmlspecialchars($bsAnchor, ENT_QUOTES, 'UTF-8'); ?>" data-bs-jump="<?php echo htmlspecialchars($bsAnchor, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($bsCard[1], ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
            <a href="email-templates.php">Email templates &rarr;</a>
        </nav>
        <div class="bs-empty" id="bs-empty" hidden>
            <p id="bs-empty-text">No settings match.</p>
            <button type="button" class="btn btn-outline" id="bs-clear"><i class="fas fa-xmark"></i> Clear</button>
        </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo $message; ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $error; ?></div>
            </div>
        <?php endif; ?>

        <?php if (!$rh_templates_only): ?>
        <section class="bs-group" data-bs-section="web" aria-labelledby="bs-group-web">
            <h2 class="bs-group__title" id="bs-group-web">Website &amp; booking</h2>

        <div class="rh-panel settings-card" id="maintenance"<?php echo $bsAttr('maintenance'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Frontend Maintenance Mode</h2></div>

            <div class="current-value">
                <i class="fas fa-<?php echo $site_maintenance_enabled ? 'triangle-exclamation' : 'check-circle'; ?>" style="color: <?php echo $site_maintenance_enabled ? '#dc3545' : '#28a745'; ?>;"></i>
                <div class="current-value-info">
                    <h3>Frontend is <?php echo $site_maintenance_enabled ? 'In Maintenance' : 'Live'; ?></h3>
                    <div class="value"><?php echo $site_maintenance_enabled ? 'Public pages blocked (admin stays online)' : 'Public pages available'; ?></div>
                </div>
            </div>

            <?php echo $rh_site_switch_note; ?>
            <form method="POST" action="booking-settings.php" id="site-maintenance-toggle-form">
                <input type="hidden" name="toggle_site_maintenance" value="1">
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox"
                            id="site_maintenance_enabled"
                            name="site_maintenance_enabled"
                            value="1"
                            <?php echo $site_maintenance_enabled ? 'checked' : ''; ?><?php echo $rhSwDis; ?>
                            onchange="toggleSiteMaintenance()">
                        <span style="font-weight: 600; color: #1A1A1A;">Enable Full Frontend Maintenance Mode</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        One click immediately switches all public frontend pages into maintenance mode and keeps admin and API routes accessible.
                    </p>
                </div>
            </form>

            <form method="POST" action="booking-settings.php">
                <input type="hidden" name="save_site_maintenance_message" value="1">
                <div class="form-group">
                    <label for="site_maintenance_message"><strong>Maintenance Message</strong></label>
                    <textarea id="site_maintenance_message"
                        name="site_maintenance_message"
                        class="form-control"
                        rows="4"<?php echo $rhSwDis; ?>
                        placeholder="This message appears for all visitors while maintenance mode is active."><?php echo htmlspecialchars($site_maintenance_message); ?></textarea>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        This message is shown on every blocked public page while maintenance mode is enabled.
                    </p>
                </div>

                <?php if ($canSiteSwitches): ?>
                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Maintenance Message
                </button>
                <?php endif; ?>
            </form>
        </div>

        <?php if ($mod_bookings): ?>
        <div class="rh-panel settings-card" id="booking-status"<?php echo $bsAttr('booking-status'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Booking System Status</h2></div>

            <div class="current-value">
                <i class="fas fa-<?php echo $booking_enabled ? 'check-circle' : 'times-circle'; ?>" style="color: <?php echo $booking_enabled ? '#28a745' : '#dc3545'; ?>;"></i>
                <div class="current-value-info">
                    <h3>Booking System is <?php echo $booking_enabled ? 'Enabled' : 'Disabled'; ?></h3>
                    <div class="value"><?php echo $booking_enabled ? 'Active' : 'Inactive'; ?></div>
                </div>
            </div>

            <?php echo $rh_site_switch_note; ?>
            <form method="POST" action="booking-settings.php" id="booking-system-toggle-form">
                <input type="hidden" name="toggle_booking_system" value="1">
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox"
                            id="booking_system_enabled"
                            name="booking_system_enabled"
                            value="1"
                            <?php echo $booking_enabled ? 'checked' : ''; ?><?php echo $rhSwDis; ?>
                            onchange="toggleBookingSettings()">
                        <span style="font-weight: 600; color: #1A1A1A;">Enable Online Booking System</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        When disabled, all booking forms, buttons, and widgets will be hidden.
                        Guests will see a message instead of booking options.
                    </p>
                </div>
            </form>

            <?php if (!$booking_enabled): ?>
                <div class="bs-notice">
                    <h4><i class="fas fa-exclamation-triangle"></i> Booking System Disabled</h4>
                    <p>
                        The booking system is currently turned off. Enable it above to allow guests to make online reservations.
                    </p>
                    <div>
                        <a href="#disabled-settings">
                            <i class="fas fa-cog"></i> Configure Disabled Message
                        </a>

                    </div>
                </div>

                <div id="disabled-settings" style="margin-top: 25px;">
                    <h3 style="color: #7E684B; margin-bottom: 20px;"><i class="fas fa-sliders-h"></i> Disabled Mode Settings</h3>

                    <?php echo $rh_site_switch_note; ?>
                    <form method="POST" action="booking-settings.php">
                        <fieldset<?php echo $rhSwDis; ?> style="border:0;padding:0;margin:0;min-width:0;">
                        <div class="form-group">
                            <label for="booking_disabled_action"><strong>Action When Disabled</strong></label>
                            <select id="booking_disabled_action" name="booking_disabled_action" class="form-control" onchange="toggleDisabledAction()">
                                <option value="message" <?php echo $disabled_action === 'message' ? 'selected' : ''; ?>>Show Custom Message</option>
                                <option value="contact" <?php echo $disabled_action === 'contact' ? 'selected' : ''; ?>>Show Contact Information</option>
                                <option value="redirect" <?php echo $disabled_action === 'redirect' ? 'selected' : ''; ?>>Redirect to URL</option>
                            </select>
                            <p class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Choose what happens when guests try to access booking features
                            </p>
                        </div>

                        <div class="form-group" id="redirect-url-group" style="display: <?php echo $disabled_action === 'redirect' ? 'block' : 'none'; ?>;">
                            <label for="booking_disabled_redirect_url"><strong>Redirect URL</strong></label>
                            <input type="text"
                                id="booking_disabled_redirect_url"
                                name="booking_disabled_redirect_url"
                                class="form-control"
                                value="<?php echo htmlspecialchars($disabled_redirect_url ?? '/'); ?>"
                                placeholder="/">
                            <p class="help-text">
                                <i class="fas fa-info-circle"></i>
                                URL to redirect to (e.g., /contact or https://external-booking.com)
                            </p>
                        </div>

                        <div class="form-group" id="message-group" style="display: <?php echo ($disabled_action === 'message' || $disabled_action === 'contact') ? 'block' : 'none'; ?>;">
                            <label for="booking_disabled_message"><strong>Custom Message</strong></label>
                            <textarea id="booking_disabled_message"
                                name="booking_disabled_message"
                                class="form-control"
                                rows="4"
                                placeholder="For booking inquiries, please contact us at [phone] or [email]"><?php echo htmlspecialchars($disabled_message); ?></textarea>
                            <p class="help-text">
                                <i class="fas fa-info-circle"></i>
                                Use [phone], [email], or [contact info] placeholders to insert your contact info automatically
                            </p>
                        </div>
                        </fieldset>

                        <?php if ($canSiteSwitches): ?>
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                        <?php endif; ?>
                    </form>
                </div>

            <?php endif; ?>
            </div><!-- /booking-status -->

            <script>
                // Inject CSRF token into all POST forms on this page (anti-CSRF protection)
                window._bsCsrf = <?php echo json_encode($csrf_token); ?>;

                function injectBookingSettingsCsrfTokens() {
                    document.querySelectorAll('form[method="POST"], form[method="post"], form[action="booking-settings.php"]').forEach(function(f) {
                        if (!f.querySelector('[name="csrf_token"]')) {
                            var inp = document.createElement('input');
                            inp.type = 'hidden';
                            inp.name = 'csrf_token';
                            inp.value = window._bsCsrf;
                            f.appendChild(inp);
                        }
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', injectBookingSettingsCsrfTokens);
                } else {
                    injectBookingSettingsCsrfTokens();
                }

                function toggleSiteMaintenance() {
                    const form = document.getElementById('site-maintenance-toggle-form');
                    if (form) {
                        form.submit();
                    }
                }

                function toggleBookingSettings() {
                    const form = document.getElementById('booking-system-toggle-form');
                    if (form) {
                        form.submit();
                    }
                }

                function toggleDisabledAction() {
                    const actionSelect = document.getElementById('booking_disabled_action');
                    const redirectGroup = document.getElementById('redirect-url-group');
                    const messageGroup = document.getElementById('message-group');

                    if (!actionSelect || !redirectGroup || !messageGroup) {
                        return;
                    }

                    const action = actionSelect.value;
                    redirectGroup.style.display = action === 'redirect' ? 'block' : 'none';
                    messageGroup.style.display = (action === 'message' || action === 'contact') ? 'block' : 'none';
                }

                toggleDisabledAction();
            </script>

            <?php rh_hotel_details_render($user, $csrf_token); ?>

            <?php endif; /* $mod_bookings — Booking status, script, Hotel details */ ?>
        </section>

            <?php if ($mod_bookings): ?>
        <section class="bs-group" data-bs-section="rooms" aria-labelledby="bs-group-rooms">
            <h2 class="bs-group__title" id="bs-group-rooms">Rooms &amp; rates rules</h2>

            <div class="rh-panel settings-card" id="advance-booking"<?php echo $bsAttr('advance-booking'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Advance Booking Configuration</h2></div>

                <form method="POST" action="booking-settings.php">
                    <div class="form-group">
                        <label for="max_advance_booking_days">Maximum Advance Booking Days</label>
                        <input type="number"
                            id="max_advance_booking_days"
                            name="max_advance_booking_days"
                            class="form-control"
                            value="<?php echo $current_max_days; ?>"
                            min="1"
                            max="365"
                            required>
                        <p class="help-text">
                            <i class="fas fa-info-circle"></i>
                            Guests can only make bookings up to this many days in advance.
                            Default is 30 days (one month). Minimum is 1 day, maximum is 365 days.
                        </p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>

                <details class="tips-box">
                    <summary><i class="fas fa-circle-info"></i> How this affects your website</summary>
                    <div class="tips-body">
                        <ul>
                            <li>Date pickers only allow dates within this limit</li>
                            <li>Server-side validation rejects bookings beyond this date</li>
                            <li>Guests see a clear message about the booking window</li>
                        </ul>
                    </div>
                </details>
            </div>

            <div class="rh-panel settings-card" id="tentative"<?php echo $bsAttr('tentative'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Tentative Bookings</h2></div>

                <?php $tentativeCurrentlyEnabled = getSetting('tentative_bookings_enabled', '1') !== '0'; ?>
                <div class="current-value">
                    <i class="fas fa-<?php echo $tentativeCurrentlyEnabled ? 'check-circle' : 'times-circle'; ?>" style="color: <?php echo $tentativeCurrentlyEnabled ? '#28a745' : '#dc3545'; ?>;"></i>
                    <div class="current-value-info">
                        <h3>Tentative Bookings are <?php echo $tentativeCurrentlyEnabled ? 'Enabled' : 'Disabled'; ?></h3>
                        <div class="value"><?php echo $tentativeCurrentlyEnabled ? 'Active' : 'Inactive'; ?></div>
                    </div>
                </div>

                <form method="POST" action="booking-settings.php#tentative" id="tentative-toggle-form">
                    <input type="hidden" name="toggle_tentative_bookings" value="1">
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                            <input type="checkbox"
                                id="tentative_bookings_enabled"
                                name="tentative_bookings_enabled"
                                value="1"
                                <?php echo $tentativeCurrentlyEnabled ? 'checked' : ''; ?>
                                onchange="onTentativeToggleChange()">
                            <span style="font-weight: 600; color: #1A1A1A;">Allow Tentative (Hold) Bookings</span>
                        </label>
                        <p class="help-text">
                            <i class="fas fa-info-circle"></i>
                            When enabled, guests can hold a room for
                            <?php echo (int)getSetting('tentative_duration_hours', 48); ?> hours without immediate payment.
                            When disabled, the tentative option is hidden on both the public booking page and admin booking forms.
                        </p>
                        <div id="tentative-disable-warning" style="display:<?php echo $tentativeCurrentlyEnabled ? 'none' : 'block'; ?>;margin-top:10px;padding:10px 14px;background:#fff7ed;border:1px solid #fb923c;border-radius:8px;font-size:13px;color:#9a3412;">
                            <i class="fas fa-triangle-exclamation"></i>
                            <strong>Disabled:</strong> Guests cannot create new tentative holds. Existing holds in the system are unaffected.
                        </div>
                        <button type="submit" id="tentative-save-btn" class="btn-submit" style="margin-top: 14px;">
                            <i class="fas fa-save"></i> <span id="tentative-save-label"><?php echo $tentativeCurrentlyEnabled ? 'Save — Keep Enabled' : 'Save — Keep Disabled'; ?></span>
                        </button>
                    </div>
                </form>

                <script>
                    function onTentativeToggleChange() {
                        var cb = document.getElementById('tentative_bookings_enabled');
                        var warning = document.getElementById('tentative-disable-warning');
                        var label = document.getElementById('tentative-save-label');
                        var btn = document.getElementById('tentative-save-btn');
                        if (cb.checked) {
                            warning.style.display = 'none';
                            label.textContent = 'Save — Enable Tentative Bookings';
                            btn.style.background = '';
                        } else {
                            warning.style.display = 'block';
                            label.textContent = 'Save — Disable Tentative Bookings';
                            btn.style.background = '#c0392b';
                        }
                    }
                    document.getElementById('tentative-toggle-form').addEventListener('submit', function(e) {
                        var cb = document.getElementById('tentative_bookings_enabled');
                        if (!cb.checked) {
                            if (!window.confirm('Disable tentative bookings? Guests will no longer be able to place tentative holds from the public booking page or admin forms.\n\nExisting tentative bookings in the system will not be affected.')) {
                                e.preventDefault();
                            }
                        }
                    });
                </script>

                <details class="tips-box">
                    <summary><i class="fas fa-circle-info"></i> What happens when tentative is disabled</summary>
                    <div class="tips-body">
                        <ul>
                            <li>The "Tentative Booking" card on the public booking page is removed entirely</li>
                            <li>The "Tentative (hold)" option in admin booking forms is removed</li>
                            <li>Existing tentative bookings are unaffected — they remain in the system</li>
                            <li>Email templates for tentative bookings remain configured for future use</li>
                        </ul>
                    </div>
                </details>
            </div>

            <div class="rh-panel settings-card" id="booking-references"<?php echo $bsAttr('booking-references'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Booking references</h2></div>
                <?php $refPrefixNow = rh_booking_reference_prefix(); ?>
                <form method="POST" action="booking-settings.php#booking-references">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="save_booking_reference_prefix" value="1">
                    <?php echo $rh_site_switch_note; ?>
                    <div class="form-group">
                        <label for="booking_reference_prefix">Reference prefix (2-6 letters or digits)</label>
                        <input type="text" id="booking_reference_prefix" name="booking_reference_prefix" maxlength="6" pattern="[A-Za-z0-9]{2,6}" required<?php echo $rhSwDis; ?>
                               value="<?php echo htmlspecialchars($refPrefixNow, ENT_QUOTES); ?>" style="text-transform:uppercase;max-width:160px;">
                        <p class="help-text">New bookings get references like <strong><?php echo htmlspecialchars($refPrefixNow . date('Y'), ENT_QUOTES); ?>012345</strong>. Used by the website, Create Booking and the API. Existing bookings keep their reference.</p>
                    </div>
                    <?php if ($canSiteSwitches): ?>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Prefix</button>
                    <?php endif; ?>
                </form>
            </div>
        </section>

        <section class="bs-group" data-bs-section="money" aria-labelledby="bs-group-money">
            <h2 class="bs-group__title" id="bs-group-money">Money &amp; documents</h2>

            <div class="rh-panel settings-card" id="cancellation"<?php echo $bsAttr('cancellation'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Refunds &amp; cancellations</h2></div>
                <?php
                $cancelModeNow = getCancellationRefundMode();
                $canEditRefundRules = hasPermission((int)($user['id'] ?? 0), 'finance_settings');
                ?>
                <div class="current-value">
                    <i class="fas fa-coins" style="color: #7E684B;"></i>
                    <div class="current-value-info">
                        <h3>Current handling</h3>
                        <div class="value"><?php echo htmlspecialchars(getCancellationRefundModeLabel($cancelModeNow)); ?></div>
                    </div>
                </div>
                <form method="POST" action="booking-settings.php#cancellation">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="save_cancellation_refund_mode" value="1">
                    <div class="form-group">
                        <?php if (!$canEditRefundRules): ?>
                            <p class="help-text"><i class="fas fa-lock"></i> You can view these rules. Only users with the "Change VAT &amp; refund settings" permission can change them.</p>
                        <?php endif; ?>
                        <h3 style="margin:0 0 8px;">When a room booking is cancelled</h3>
                        <fieldset <?php echo $canEditRefundRules ? '' : 'disabled'; ?> style="border:0;padding:0;margin:0;min-width:0;">
                        <?php
                        $cancelModeOptions = [
                            'void_refund_all'  => ['Void the bill and refund everything paid', 'The booking owes nothing and the full net amount paid is refunded automatically.'],
                            'void_keep_credit' => ['Void the bill, keep payments as guest credit', 'The booking owes nothing but no refund is made automatically. Money paid shows as owed back to the guest, for staff to refund or issue a credit note.'],
                            'keep_first_night' => ['Charge the first night, refund the rest', 'The guest is charged the first night (nightly rate including its VAT/levy share); the bill is reduced to that amount and the rest of the net paid is refunded.'],
                        ];
                        foreach ($cancelModeOptions as $modeKey => [$modeTitle, $modeHelp]): ?>
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:12px;">
                                <input type="radio" name="cancellation_refund_mode" value="<?php echo htmlspecialchars($modeKey, ENT_QUOTES); ?>" <?php echo $cancelModeNow === $modeKey ? 'checked' : ''; ?> style="margin-top:4px;">
                                <span>
                                    <span style="font-weight:600;color:#1A1A1A;"><?php echo htmlspecialchars($modeTitle); ?></span><br>
                                    <span class="help-text"><?php echo htmlspecialchars($modeHelp); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Applies to every cancellation made after you save. Bookings already cancelled are not changed. Folio charges already posted to a booking stay billable.</p>

                        <?php
                        $ruleGroups = [
                            'guest_self_cancel_refund' => ['When a guest cancels online', [
                                'pending' => ['Staff approve the refund', 'The refund is queued as pending until staff settle it.'],
                                'auto'    => ['Refund immediately', 'The refund is marked completed the moment the guest cancels.'],
                            ]],
                            'nonroom_cancel_mode' => ['When a conference, event or gym account is cancelled', [
                                'keep_credit' => ['Keep payments as credit', 'The bill is voided and money paid stays as credit owed back; staff refund it manually.'],
                                'refund_all'  => ['Refund everything paid', 'The net amount paid is refunded automatically when the account is cancelled.'],
                            ]],
                            'refund_method_default' => ['How refunds are paid out', [
                                'original' => ['Always to the original payment method', 'Refunds go back the way the money came in and staff cannot change the method.'],
                                'ask'      => ['Staff choose on the refund screen', 'The refund screen starts on the original method but staff may pick another.'],
                            ]],
                        ];
                        foreach ($ruleGroups as $ruleKey => [$ruleTitle, $ruleChoices]):
                            $ruleNow = rh_refund_rule($ruleKey); ?>
                            <h3 style="margin:18px 0 8px;"><?php echo htmlspecialchars($ruleTitle); ?></h3>
                            <?php foreach ($ruleChoices as $choiceKey => [$choiceTitle, $choiceHelp]): ?>
                                <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:12px;">
                                    <input type="radio" name="<?php echo htmlspecialchars($ruleKey, ENT_QUOTES); ?>" value="<?php echo htmlspecialchars($choiceKey, ENT_QUOTES); ?>" <?php echo $ruleNow === $choiceKey ? 'checked' : ''; ?> style="margin-top:4px;">
                                    <span>
                                        <span style="font-weight:600;color:#1A1A1A;"><?php echo htmlspecialchars($choiceTitle); ?></span><br>
                                        <span class="help-text"><?php echo htmlspecialchars($choiceHelp); ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                        </fieldset>
                        <?php if ($canEditRefundRules): ?>
                        <button type="submit" class="btn-submit" style="margin-top: 14px;"><i class="fas fa-save"></i> Save Refund Settings</button>
                        <?php endif; ?>
                    </div>
                </form>
                <?php $gnsNow = rh_group_noshow_charge_mode(); $gnsNow = $gnsNow === '' ? '0' : $gnsNow; ?>
                <form method="POST" action="booking-settings.php#group-noshow-charge" id="group-noshow-charge">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="save_group_noshow_charge" value="1">
                    <div class="form-group">
                        <h3 style="margin:18px 0 8px;">Charge no-show / expired rooms in a group</h3>
                        <p class="help-text">For a room that is part of a group booking (2 or more rooms) when it is marked a no-show or expires. Single bookings are never changed. The charge is posted once, as its own line on that room's bill, and anything paid above it (and above any extras) goes back to the guest: as a refund when the no-show refund rule is First night or Full, or as credit owed to the guest when it is No automatic refund.</p>
                        <fieldset <?php echo $canEditRefundRules ? '' : 'disabled'; ?> style="border:0;padding:0;margin:0;min-width:0;">
                        <?php foreach ($rh_group_noshow_choices as $gnsKey => [$gnsTitle, $gnsHelp]): ?>
                            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin-bottom:12px;">
                                <input type="radio" name="group_noshow_charge" value="<?php echo htmlspecialchars((string)$gnsKey, ENT_QUOTES); ?>" <?php echo $gnsNow === (string)$gnsKey ? 'checked' : ''; ?> style="margin-top:4px;">
                                <span>
                                    <span style="font-weight:600;color:#1A1A1A;"><?php echo htmlspecialchars($gnsTitle); ?></span><br>
                                    <span class="help-text"><?php echo htmlspecialchars($gnsHelp); ?></span>
                                </span>
                            </label>
                        <?php endforeach; ?>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Applies only to rooms that become a no-show or expire after you save. Rooms already charged are never charged again.</p>
                        </fieldset>
                        <?php if ($canEditRefundRules): ?>
                        <button type="submit" class="btn-submit" style="margin-top: 14px;"><i class="fas fa-save"></i> Save No-show Charge</button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="rh-panel settings-card" id="tourism-levy"<?php echo $bsAttr('tourism-levy'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Tourism Levy / City Tax</h2></div>

                <form method="POST" action="booking-settings.php">
                    <input type="hidden" name="tourism_levy_settings" value="1">

                    <div class="form-group">
                        <label style="display: flex; align-items: center; cursor: pointer;">
                            <input type="checkbox"
                                id="tourism_levy_enabled"
                                name="tourism_levy_enabled"
                                value="1"
                                style="margin-right: 10px;"
                                <?php echo (getSetting('tourism_levy_enabled', '0') === '1') ? 'checked' : ''; ?>>
                            <strong>Enable Tourism Levy</strong>
                        </label>
                        <p class="help-text" style="margin-top: 10px;">
                            <i class="fas fa-info-circle"></i>
                            When enabled, a tourism levy (city tax) will be automatically added to all new bookings.
                        </p>
                    </div>

                    <div class="form-group">
                        <label for="tourism_levy_percent">Tourism Levy Percentage (%)</label>
                        <input type="number"
                            id="tourism_levy_percent"
                            name="tourism_levy_percent"
                            class="form-control"
                            value="<?php echo htmlspecialchars(getSetting('tourism_levy_percent', '0')); ?>"
                            min="0"
                            max="100"
                            step="0.01"
                            required>
                        <p class="help-text">
                            <i class="fas fa-info-circle"></i>
                            The percentage of the total booking amount (room rate + child supplement) to charge as tourism levy.
                            Common values range from 1% to 5% depending on local regulations.
                        </p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Tourism Levy Settings
                    </button>
                </form>

                <details class="tips-box">
                    <summary><i class="fas fa-circle-info"></i> How Tourism Levy Works</summary>
                    <div class="tips-body">
                        <ul>
                            <li><strong>Calculation:</strong> (Room Rate + Child Supplement) × (Levy % / 100)</li>
                            <li><strong>Display:</strong> A hint "Includes X% Tourism Levy" shown on the booking page</li>
                            <li><strong>Invoices:</strong> Appears as a separate line item on invoices</li>
                            <li><strong>Existing Bookings:</strong> Only affects new bookings, not existing ones</li>
                            <li><strong>Compliance:</strong> Ensure your rate complies with local tourism tax regulations</li>
                        </ul>
                    </div>
                </details>
            </div>

            <div class="rh-panel settings-card" id="document-branding"<?php echo $bsAttr('document-branding'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Document branding</h2></div>
                <?php
                $canEditBranding = hasPermission((int)($user['id'] ?? 0), 'finance_settings');
                $brandPrimaryNow = rh_theme_hex(getSetting('brand_primary_color', ''), '#524b3f');
                $brandAccentNow = rh_theme_hex(getSetting('brand_accent_color', ''), '#9b8f7e');
                ?>
                <form method="POST" action="booking-settings.php#document-branding">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="save_document_branding" value="1">
                    <?php if (!$canEditBranding): ?>
                        <p class="help-text"><i class="fas fa-lock"></i> You can view these. Only users with the "Change VAT &amp; refund settings" permission can change them.</p>
                    <?php endif; ?>
                    <fieldset <?php echo $canEditBranding ? '' : 'disabled'; ?> style="border:0;padding:0;margin:0;min-width:0;">
                        <div class="form-group">
                            <label for="brand_primary_color">Primary colour (table headers, total bar, title band)</label>
                            <input type="color" id="brand_primary_color" name="brand_primary_color" value="<?php echo htmlspecialchars($brandPrimaryNow, ENT_QUOTES); ?>" pattern="#[0-9a-fA-F]{6}" style="width:70px;height:40px;padding:2px;">
                        </div>
                        <div class="form-group">
                            <label for="brand_accent_color">Accent colour (small labels and rules)</label>
                            <input type="color" id="brand_accent_color" name="brand_accent_color" value="<?php echo htmlspecialchars($brandAccentNow, ENT_QUOTES); ?>" pattern="#[0-9a-fA-F]{6}" style="width:70px;height:40px;padding:2px;">
                        </div>
                        <div class="form-group">
                            <label for="document_footer_text">Footer line (printed at the bottom of every PDF)</label>
                            <input type="text" id="document_footer_text" name="document_footer_text" maxlength="160" value="<?php echo htmlspecialchars((string)getSetting('document_footer_text', ''), ENT_QUOTES); ?>" placeholder="Thank you for staying with us">
                        </div>
                        <div class="form-group">
                            <label for="document_terms_text">Terms / note under the totals</label>
                            <textarea id="document_terms_text" name="document_terms_text" rows="3" maxlength="600"><?php echo htmlspecialchars((string)getSetting('document_terms_text', ''), ENT_QUOTES); ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="document_logo_height_mm">Logo height on documents (10-40 mm)</label>
                            <input type="number" id="document_logo_height_mm" name="document_logo_height_mm" min="10" max="40" step="0.5" value="<?php echo htmlspecialchars((string)getSetting('document_logo_height_mm', ''), ENT_QUOTES); ?>" placeholder="24">
                            <p class="help-text">Leave blank for the default (24 mm).</p>
                        </div>
                    </fieldset>
                    <?php if ($canEditBranding): ?>
                    <button type="submit" class="btn-submit" style="margin-top: 8px;"><i class="fas fa-save"></i> Save Document Branding</button>
                    <?php endif; ?>
                </form>
            </div>
        </section>

            <?php endif; /* $mod_bookings — Rooms & rates rules, Money & documents */ ?>

        <section class="bs-group" data-bs-section="email" aria-labelledby="bs-group-email">
            <h2 class="bs-group__title" id="bs-group-email">Email &amp; notifications</h2>

            <div class="rh-panel settings-card" id="email-config"<?php echo $bsAttr('email-config'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Email Configuration</h2></div>

                <?php
                $email_settings = getAllEmailSettings();
                $current_settings = [];
                foreach ($email_settings as $key => $setting) {
                    $current_settings[$key] = $setting['value'];
                }
                ?>

                <form method="POST" action="booking-settings.php">
                    <input type="hidden" name="email_settings" value="1">

                    <h3 style="color: #1A1A1A; margin-top: 25px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                        <i class="fas fa-server"></i> SMTP Server Settings
                    </h3>
                    <?php if (!$canEmailServer): ?>
                        <p class="help-text"><i class="fas fa-lock"></i> Only a super admin can change the email server and the switches under Email Settings. You can still change the sender name and the email addresses below.</p>
                    <?php endif; ?>
                    <fieldset<?php echo $canEmailServer ? '' : ' disabled'; ?> style="border:0;padding:0;margin:0;min-width:0;">

                    <div class="form-group">
                        <label for="smtp_host">SMTP Host *</label>
                        <input type="text" id="smtp_host" name="smtp_host" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['smtp_host'] ?? ''); ?>" required>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Your SMTP server hostname (e.g., mail.yourdomain.com, smtp.gmail.com)</p>
                    </div>

                    <div class="form-group">
                        <label for="smtp_port">SMTP Port *</label>
                        <input type="number" id="smtp_port" name="smtp_port" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['smtp_port'] ?? ''); ?>" min="1" max="65535" required>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Common ports: 465 (SSL), 587 (TLS), 25 (Standard)</p>
                    </div>

                    <div class="form-group">
                        <label for="smtp_username">SMTP Username *</label>
                        <input type="text" id="smtp_username" name="smtp_username" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['smtp_username'] ?? ''); ?>" required>
                        <p class="help-text"><i class="fas fa-info-circle"></i> The mailbox that sends every email (usually its full email address). Mail servers only allow sending as this address, so guests see it in the From line next to the sender name. It is not shown anywhere else.</p>
                    </div>

                    <div class="form-group">
                        <label for="smtp_password">SMTP Password</label>
                        <input type="password" id="smtp_password" name="smtp_password" class="form-control"
                            value="" placeholder="Leave blank to keep current password">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Your email account password. Only enter if you want to change it.</p>
                    </div>

                    <div class="form-group">
                        <label for="smtp_secure">SMTP Security</label>
                        <select id="smtp_secure" name="smtp_secure" class="form-control">
                            <option value="ssl" <?php echo ($current_settings['smtp_secure'] ?? 'ssl') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
                            <option value="tls" <?php echo ($current_settings['smtp_secure'] ?? 'ssl') === 'tls' ? 'selected' : ''; ?>>TLS</option>
                            <option value="" <?php echo empty($current_settings['smtp_secure'] ?? '') ? 'selected' : ''; ?>>None</option>
                        </select>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Security protocol for SMTP connection</p>
                    </div>
                    </fieldset>

                    <h3 style="color: #1A1A1A; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                        <i class="fas fa-user"></i> Email Identity
                    </h3>

                    <div class="form-group">
                        <label for="email_from_name">Sender name *</label>
                        <input type="text" id="email_from_name" name="email_from_name" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_from_name'] ?? ''); ?>" required>
                        <p class="help-text"><i class="fas fa-info-circle"></i> The name guests see on every email, for example the hotel's name.</p>
                    </div>

                    <div class="form-group">
                        <label for="email_from_email">Reply-to and contact email (optional)</label>
                        <input type="email" id="email_from_email" name="email_from_email" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_from_email'] ?? ''); ?>">
                        <p class="help-text"><i class="fas fa-info-circle"></i> When guests reply to an email, their reply goes here, and it is printed as the contact email on emails and documents. Use the hotel's own address. Leave blank if the hotel has no email domain yet: the main contact email from Hotel Details is used instead.</p>
                        <?php
                        $rhGuestContact = rh_guest_contact_email();
                        $rhSavedContact = trim((string)($current_settings['email_from_email'] ?? ''));
                        $rhMailbox = trim((string)($current_settings['smtp_username'] ?? ''));
                        ?>
                        <p class="help-text"><i class="fas fa-envelope"></i> Guests currently see:
                            <?php if ($rhGuestContact !== ''): ?><strong><?php echo htmlspecialchars($rhGuestContact); ?></strong><?php else: ?>no contact email (set one here or the main contact email in Hotel Details)<?php endif; ?></p>
                        <?php if ($rhSavedContact !== '' && $rhMailbox !== '' && strcasecmp($rhSavedContact, $rhMailbox) === 0): ?>
                        <p class="help-text"><i class="fas fa-triangle-exclamation"></i> This is the sending mailbox, so guests see it here too. Change it to the hotel's own address once the hotel has its own mailbox.</p>
                        <?php endif; ?>
                    </div>

                    <div class="form-group">
                        <label for="email_reply_to_backup">Backup reply-to emails (optional)</label>
                        <input type="text" id="email_reply_to_backup" name="email_reply_to_backup" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_reply_to_backup'] ?? ''); ?>"
                            placeholder="frontdesk@example.com, manager@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Up to 5, comma-separated. When guests reply to an email, their reply also goes to these addresses. Guests can see them in the reply line, so don't use an address guests shouldn't see. For a hidden monitoring copy, use Backup staff copy emails instead. Only used when there is a reply-to and contact email (or a main contact email in Hotel Details).</p>
                        <?php $rhReplyBackups = array_slice(rh_mail_reply_to_addresses(), 1); ?>
                        <p class="help-text"><i class="fas fa-envelope"></i> Replies currently also go to:
                            <?php if ($rhReplyBackups): ?><strong><?php echo htmlspecialchars(implode(', ', $rhReplyBackups)); ?></strong><?php elseif ($rhGuestContact === '' && trim((string)($current_settings['email_reply_to_backup'] ?? '')) !== ''): ?>no backup addresses (there is no reply-to and contact email for them to back up)<?php else: ?>no backup addresses<?php endif; ?></p>
                    </div>

                    <div class="form-group">
                        <label for="email_admin_email">Staff copy email</label>
                        <input type="email" id="email_admin_email" name="email_admin_email" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_admin_email'] ?? ''); ?>">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Gets: a hidden copy of every email while the switch below is on; a copy of bookings created in the admin panel and of conference confirmations; new gym membership enquiries; booking, conference, gym and expired-hold alerts when their own boxes and fallbacks are blank; the end-of-day report when no report recipient is set. Guests never see it.</p>
                    </div>

                    <div class="form-group">
                        <label for="email_admin_backup">Backup staff copy emails (optional)</label>
                        <input type="text" id="email_admin_backup" name="email_admin_backup" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_admin_backup'] ?? ''); ?>"
                            placeholder="owner@example.com, accounts@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Up to 5, comma-separated. Each gets the same hidden copy as the staff copy email, while the switch below is on. Guests never see them. They do not receive the alerts or reports that fall back to the staff copy email.</p>
                        <?php
                        $rhStaffBackups = rh_mail_staff_backup_addresses();
                        $rhBccOn = ($current_settings['email_bcc_admin'] ?? '1') === '1';
                        ?>
                        <p class="help-text"><i class="fas fa-envelope"></i> Hidden copies currently also go to:
                            <?php if ($rhStaffBackups): ?><strong><?php echo htmlspecialchars(implode(', ', $rhStaffBackups)); ?></strong><?php if (!$rhBccOn): ?> (only while the hidden-copy switch below is on; it is off)<?php endif; ?><?php else: ?>no backup addresses<?php endif; ?></p>
                    </div>

                    <h3 style="color: #1A1A1A; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                        <i class="fas fa-sliders-h"></i> Email Settings
                    </h3>
                    <fieldset<?php echo $canEmailServer ? '' : ' disabled'; ?> style="border:0;padding:0;margin:0;min-width:0;">

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_bcc_admin" name="email_bcc_admin" value="1"
                                <?php echo ($current_settings['email_bcc_admin'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Send the staff copy email a hidden copy of every email</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Guests can't see this copy. It covers guest emails and staff alerts (new bookings, conference and gym alerts, expired holds, contact-form messages), once each: an email that already goes to the staff copy email is not copied again. Automated reminders use their own switch on the Automated Emails page.</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_development_mode" name="email_development_mode" value="1"
                                <?php echo ($current_settings['email_development_mode'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Preview only (testing on this computer)</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Only works when the system runs on a local computer: emails are saved as previews instead of being sent. It has no effect on the live website.</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_log_enabled" name="email_log_enabled" value="1"
                                <?php echo ($current_settings['email_log_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Keep a log of sent emails</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Records each email sent or failed (time, recipient, subject) so delivery problems can be traced.</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_preview_enabled" name="email_preview_enabled" value="1"
                                <?php echo ($current_settings['email_preview_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Save a preview copy of each email</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Keeps a viewable copy of each email for checking how it looks. Only used together with Preview only on a local computer.</p>
                    </div>
                    </fieldset>

                    <div class="form-group">
                        <label for="invoice_recipients">Hotel copies of invoices, statements and credit notes</label>
                        <input type="text" id="invoice_recipients" name="invoice_recipients" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['invoice_recipients'] ?? ''); ?>"
                            placeholder="accounts@example.com, manager@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Comma-separated. Each address gets a hidden copy of every invoice, statement, credit note and receipt emailed to a guest; the guest doesn't see these copies. The sending mailbox also gets a hidden copy of invoices and statements automatically.</p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Email Settings
                    </button>
                </form>

                <form method="POST" action="booking-settings.php" id="email-settings-test-form" style="margin-top:24px;padding-top:16px;border-top:1px solid #e0e0e0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="email_settings_test" value="1">
                    <h3 style="color: #1A1A1A; margin-top: 0; margin-bottom: 10px;">
                        <i class="fas fa-paper-plane"></i> Test these settings
                    </h3>
                    <p class="help-text"><i class="fas fa-info-circle"></i> Sends one test email using the saved settings, with the same sender, reply-to and hidden copies as a real email. Save your changes first.</p>
                    <div class="form-group">
                        <label for="email_test_to">Send a test to</label>
                        <input type="email" id="email_test_to" name="email_test_to" class="form-control" value="" autocomplete="off"
                            placeholder="you@example.com" required maxlength="254" style="min-height:44px;">
                    </div>
                    <button type="submit" class="btn-submit" id="email-settings-test-btn" style="min-height:44px;">
                        <i class="fas fa-paper-plane"></i> Send test email
                    </button>
                    <div id="email-settings-test-result" role="status" aria-live="polite" style="margin-top:12px;<?php echo $rh_email_test_result === null ? 'display:none;' : ''; ?>">
                        <?php if ($rh_email_test_result !== null): ?>
                        <p class="help-text"><strong><?php echo htmlspecialchars((string)$rh_email_test_result['message'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
                        <ul class="help-text" style="margin:6px 0 0 18px;">
                            <?php foreach ($rh_email_test_result['summary'] as [$rhEtL, $rhEtV]): ?>
                            <li><?php echo htmlspecialchars((string)$rhEtL, ENT_QUOTES, 'UTF-8'); ?>: <?php echo htmlspecialchars((string)$rhEtV, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </form>
                <script>
                (function () {
                    var form = document.getElementById('email-settings-test-form');
                    if (!form || form.dataset.bound === '1') { return; }
                    form.dataset.bound = '1';
                    var btn = document.getElementById('email-settings-test-btn');
                    var box = document.getElementById('email-settings-test-result');
                    function show(message, summary, ok) {
                        box.textContent = '';
                        var p = document.createElement('p');
                        p.className = 'help-text';
                        var strong = document.createElement('strong');
                        strong.textContent = message;
                        strong.style.color = ok ? '' : '#b42318';
                        p.appendChild(strong);
                        box.appendChild(p);
                        if (summary && summary.length) {
                            var ul = document.createElement('ul');
                            ul.className = 'help-text';
                            ul.style.margin = '6px 0 0 18px';
                            summary.forEach(function (row) {
                                var li = document.createElement('li');
                                li.textContent = String(row[0]) + ': ' + String(row[1]);
                                ul.appendChild(li);
                            });
                            box.appendChild(ul);
                        }
                        box.style.display = 'block';
                    }
                    form.addEventListener('submit', function (e) {
                        if (!window.fetch || !window.FormData) { return; }
                        e.preventDefault();
                        if (!form.reportValidity()) { return; }
                        var fd = new FormData(form);
                        btn.disabled = true;
                        show('Sending the test email...', [], true);
                        fetch(form.getAttribute('action'), {
                            method: 'POST',
                            body: fd,
                            credentials: 'same-origin',
                            headers: { 'X-Requested-With': 'XMLHttpRequest' }
                        }).then(function (r) { return r.json(); }).then(function (data) {
                            show(data.success ? data.message : (data.error || 'The test email was not sent.'), data.summary || [], !!data.success);
                        }).catch(function () {
                            show('The test could not be run. Refresh the page and try again.', [], false);
                        }).then(function () {
                            btn.disabled = false;
                        });
                    });
                })();
                </script>

                <details class="tips-box" style="margin-top:10px;">
                    <summary><i class="fas fa-circle-info"></i> Email Configuration Tips</summary>
                    <div class="tips-body">
                        <ul>
                            <li><strong>Testing:</strong> Use Preview only on a local computer to test without actually sending</li>
                            <li><strong>Security:</strong> Passwords are encrypted in the database</li>
                        </ul>
                    </div>
                </details>
            </div>

            <?php if ($mod_bookings): ?>
            <div class="rh-panel settings-card" id="notification-email"<?php echo $bsAttr('notification-email'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Booking Notification Email</h2></div>
                <form method="POST" action="booking-settings.php">
                    <input type="hidden" name="booking_notification_settings" value="1">

                    <div class="form-group">
                        <label for="booking_notification_email">New website booking alerts go to</label>
                        <input type="email" id="booking_notification_email" name="booking_notification_email"
                            class="form-control" value="<?php echo htmlspecialchars($current_booking_notification_email ?? ''); ?>"
                            placeholder="reservations@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Alerts about new bookings made on the website. Leave blank to use the Staff notification email from Hotel Details, then the Staff copy email.</p>
                        <?php echo $rh_route_line(rh_booking_alert_recipient(), (string)$current_booking_notification_email); ?>
                    </div>

                    <div class="form-group">
                        <label for="booking_notification_cc_emails">Also send booking alerts to</label>
                        <input type="text" id="booking_notification_cc_emails" name="booking_notification_cc_emails"
                            class="form-control" value="<?php echo htmlspecialchars($current_booking_notification_cc_emails ?? ''); ?>"
                            placeholder="accounts@example.com, manager@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Optional, comma-separated. Each address gets its own copy of the alert. Only for bookings made on the website; bookings created in the admin panel send a copy to the Staff copy email instead.</p>
                    </div>

                    <h3 style="margin-top:24px;">Guest communication emails</h3>

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="booking_prearrival_reminder_enabled" value="1" <?php echo $current_prearrival_reminder_enabled ? 'checked' : ''; ?>>
                            <span>Send pre-arrival reminder email to guests</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="booking_prearrival_reminder_days">Days before check-in</label>
                        <input type="number" id="booking_prearrival_reminder_days" name="booking_prearrival_reminder_days"
                            class="form-control" min="1" max="14" style="max-width:120px;"
                            value="<?php echo htmlspecialchars((string)$current_prearrival_reminder_days); ?>">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Sends once per booking, 1-14 days before check-in date.</p>
                    </div>

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="booking_poststay_review_enabled" value="1" <?php echo $current_poststay_review_enabled ? 'checked' : ''; ?>>
                            <span>Send post-stay review request email to guests</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="booking_poststay_review_days">Days after check-out</label>
                        <input type="number" id="booking_poststay_review_days" name="booking_poststay_review_days"
                            class="form-control" min="0" max="14" style="max-width:120px;"
                            value="<?php echo htmlspecialchars((string)$current_poststay_review_days); ?>">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Sends once per booking, 0-14 days after check-out date (0 = checkout day).</p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Notification Email
                    </button>
                </form>
            </div>

            <?php endif; /* $mod_bookings — Booking Notification Email */ ?>
        </section>

        <section class="bs-group" data-bs-section="modules" aria-labelledby="bs-group-modules">
            <h2 class="bs-group__title" id="bs-group-modules">Modules &amp; app</h2>

            <div class="rh-panel settings-card" id="service-modules"<?php echo $bsAttr('service-modules'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">Service Modules &amp; Dedicated Notification Emails</h2></div>
                <p class="help-text">Turning a service off here also removes its page — and every page that module governs — from the guest site navigation. The Events switch lives in <a href="module-settings.php">Module Settings</a>.</p>
                <?php echo $rh_site_switch_note; ?>
                <form method="POST" action="booking-settings.php">
                    <input type="hidden" name="service_channel_settings" value="1">
                    <fieldset<?php echo $rhSwDis; ?> style="border:0;padding:0;margin:0;min-width:0;">

                    <?php /* Each row shows only when its module is active for this preset.
                             Hidden rows keep their stored values via hidden inputs — the save
                             handler treats absent fields as "disable/blank", which would
                             silently mutate settings for modules this preset doesn't manage. */ ?>
                    <?php if ($mod_conference): ?>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="conference_system_enabled" value="1" <?php echo $current_conference_system_enabled ? 'checked' : ''; ?>>
                            <span>Enable Conference page &amp; conference enquiry functionality</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="conference_email">Conference enquiry alerts go to</label>
                        <input type="email" id="conference_email" name="conference_email" class="form-control" value="<?php echo htmlspecialchars($current_conference_email ?? ''); ?>" placeholder="conference@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> New conference enquiries from the website. Leave blank to use the main contact email from Hotel Details, then the Staff copy email. When staff confirm an enquiry, a copy goes to the Staff copy email.</p>
                        <?php echo $rh_route_line(rh_conference_alert_recipient(), (string)$current_conference_email); ?>
                    </div>
                    <?php else: ?>
                    <?php if ($current_conference_system_enabled): ?><input type="hidden" name="conference_system_enabled" value="1"><?php endif; ?>
                    <input type="hidden" name="conference_email" value="<?php echo htmlspecialchars($current_conference_email ?? ''); ?>">
                    <?php endif; ?>

                    <?php if ($mod_gym): ?>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="gym_system_enabled" value="1" <?php echo $current_gym_system_enabled ? 'checked' : ''; ?>>
                            <span>Enable Gym page &amp; gym booking/enquiry functionality</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="gym_email">Gym booking alerts go to</label>
                        <input type="email" id="gym_email" name="gym_email" class="form-control" value="<?php echo htmlspecialchars($current_gym_email ?? ''); ?>" placeholder="gym@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> New gym booking requests from the website. Leave blank to use the main contact email from Hotel Details, then the Staff copy email. New membership enquiries go to the Staff copy email.</p>
                        <?php echo $rh_route_line(rh_gym_alert_recipient(), (string)$current_gym_email); ?>
                    </div>
                    <?php else: ?>
                    <?php if ($current_gym_system_enabled): ?><input type="hidden" name="gym_system_enabled" value="1"><?php endif; ?>
                    <input type="hidden" name="gym_email" value="<?php echo htmlspecialchars($current_gym_email ?? ''); ?>">
                    <?php endif; ?>

                    <?php if ($mod_pos): ?>
                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="restaurant_system_enabled" value="1" <?php echo $current_restaurant_system_enabled ? 'checked' : ''; ?>>
                            <span>Enable Restaurant page and related restaurant functionality</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="email_restaurant">Restaurant contact email</label>
                        <input type="email" id="email_restaurant" name="email_restaurant" class="form-control" value="<?php echo htmlspecialchars($current_restaurant_email ?? ''); ?>" placeholder="restaurant@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Shown to guests as the restaurant's contact email on the restaurant page and the printed menu. No alerts are sent to it. While it has never been saved, the reservations email is shown.</p>
                        <?php echo $rh_route_line(rh_restaurant_contact_recipient(), (string)$current_restaurant_email, 'Restaurant page currently shows', 'no email'); ?>
                    </div>
                    <?php else: ?>
                    <?php if ($current_restaurant_system_enabled): ?><input type="hidden" name="restaurant_system_enabled" value="1"><?php endif; ?>
                    <input type="hidden" name="email_restaurant" value="<?php echo htmlspecialchars($current_restaurant_email ?? ''); ?>">
                    <?php endif; ?>
                    </fieldset>

                    <?php if ($canSiteSwitches): ?>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Service Module Settings
                    </button>
                    <?php endif; ?>
                </form>
            </div>

            <?php if ($mod_website_cms): rh_review_sources_render($user, $csrf_token); endif; ?>

            <div class="rh-panel settings-card" id="pwa-settings"<?php echo $bsAttr('pwa-settings'); ?>>
                <div class="rh-panel__head"><h2 class="rh-panel__title">PWA Install Banner</h2></div>
                <p class="help-text">Controls how often the &ldquo;Install Admin App&rdquo; banner reappears after a staff member dismisses it.</p>
                <form method="POST" action="booking-settings.php#pwa-settings">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="save_pwa_settings" value="1">
                    <div class="form-group">
                        <label for="pwa_install_dismiss_days">Re-show banner after dismissal (days)</label>
                        <input type="number" id="pwa_install_dismiss_days" name="pwa_install_dismiss_days"
                            class="form-control" style="max-width:120px;"
                            min="1" max="365"
                            value="<?php echo (int)getSetting('pwa_install_dismiss_days', '14'); ?>">
                        <p class="help-text" style="margin-top:6px;">Default: 14 days. Set to 1 to show it every day. Set to 365 to suppress it almost permanently.</p>
                    </div>
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save PWA Settings
                    </button>
                </form>
            </div>
        </section>

            <script>
            /* Hotel Settings: search box + group filter narrow the cards and the Jump-to links.
               Hides only with the bs-out class, so it can never show a card the server did not render. */
            (function () {
                var box = document.getElementById('bs-search');
                if (!box || box.getAttribute('data-bound') === '1') { return; }
                box.setAttribute('data-bound', '1');
                var groupSel = document.getElementById('bs-group-filter');
                var countEl = document.getElementById('bs-count');
                var emptyEl = document.getElementById('bs-empty');
                var emptyText = document.getElementById('bs-empty-text');
                var clearBtn = document.getElementById('bs-clear');
                var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };
                var cards = [];
                each(document.querySelectorAll('.settings-card[data-bs-group]'), function (card) {
                    var parts = [card.getAttribute('data-bs-keywords') || ''];
                    each(card.querySelectorAll('.rh-panel__title, h3, label, legend, summary'), function (el) {
                        parts.push(el.textContent || '');
                    });
                    cards.push({ el: card, group: card.getAttribute('data-bs-group'), text: parts.join(' ').replace(/\s+/g, ' ').toLowerCase() });
                });
                var sections = document.querySelectorAll('.bs-group[data-bs-section]');
                var links = document.querySelectorAll('.bs-jump a[data-bs-jump]');

                function apply() {
                    var q = box.value.replace(/\s+/g, ' ').replace(/^ | $/g, '');
                    var words = q.toLowerCase().replace(/[,;.]+/g, ' ').split(' ').filter(function (w) { return w !== ''; });
                    var g = groupSel ? groupSel.value : '';
                    var shown = 0;
                    var visible = {};
                    cards.forEach(function (c) {
                        var ok = (g === '' || c.group === g) && words.every(function (w) { return c.text.indexOf(w) !== -1; });
                        c.el.classList.toggle('bs-out', !ok);
                        if (ok) { shown++; visible[c.el.id] = true; }
                    });
                    each(sections, function (s) {
                        s.classList.toggle('bs-out', !s.querySelector('.settings-card[data-bs-group]:not(.bs-out)'));
                    });
                    each(links, function (a) {
                        a.classList.toggle('bs-out', !visible[a.getAttribute('data-bs-jump')]);
                    });
                    var active = words.length > 0 || g !== '';
                    var groupName = (g !== '' && groupSel) ? groupSel.options[groupSel.selectedIndex].text : '';
                    countEl.textContent = active ? shown + ' of ' + cards.length + (cards.length === 1 ? ' setting' : ' settings') + ' shown' : '';
                    if (shown === 0) {
                        emptyText.textContent = 'No settings match' + (q !== '' ? ' \u201c' + q + '\u201d' : '') + (groupName !== '' ? ' in ' + groupName : '') + '.';
                        emptyEl.hidden = false;
                    } else {
                        emptyEl.hidden = true;
                    }
                }

                function clear(focus) {
                    box.value = '';
                    if (groupSel) { groupSel.value = ''; }
                    apply();
                    if (focus) { box.focus(); }
                }
                box._bsClear = clear;

                box.addEventListener('input', apply);
                if (groupSel) { groupSel.addEventListener('change', apply); }
                clearBtn.addEventListener('click', function () { clear(true); });
                box.addEventListener('keydown', function (e) {
                    if ((e.key === 'Escape' || e.key === 'Esc') && (box.value !== '' || (groupSel && groupSel.value !== ''))) {
                        e.preventDefault();
                        clear(true);
                    }
                });

                /* An anchor (#tourism-levy, global search, Jump-to) always lands: clear the filter if it hides the target. */
                function bsReveal(hash) {
                    var b = document.getElementById('bs-search');
                    if (!b || !b._bsClear || !hash || hash.length < 2) { return null; }
                    var target = null;
                    try { target = document.getElementById(decodeURIComponent(hash.slice(1))); } catch (err) { return null; }
                    if (!target || !target.closest) { return null; }
                    var card = target.closest('.settings-card');
                    var group = target.closest('.bs-group');
                    if ((card && card.classList.contains('bs-out')) || (group && group.classList.contains('bs-out'))) {
                        b._bsClear(false);
                        return target;
                    }
                    return null;
                }
                if (!window.rhBsAnchorBound) {
                    window.rhBsAnchorBound = true;
                    document.addEventListener('click', function (e) {
                        var a = e.target && e.target.closest ? e.target.closest('a[href^="#"]') : null;
                        if (a) { bsReveal(a.getAttribute('href')); }
                    });
                    window.addEventListener('hashchange', function () {
                        var t = bsReveal(window.location.hash);
                        if (t) { t.scrollIntoView(); }
                    });
                }

                var m = /[?&]q=([^&#]*)/.exec(window.location.search);
                if (m) {
                    try { box.value = decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (err) { box.value = ''; }
                    apply();
                    var t = bsReveal(window.location.hash);
                    if (t) { t.scrollIntoView(); }
                }
            })();
            </script>

            <?php endif; /* !$rh_templates_only: settings cards above */ ?>

            <?php if ($rh_templates_only): ?>
            <?php
            // Determine which tab to show first
            $activeTabKey = array_key_first($booking_template_defs);
            $allPlaceholders = [
                '{{site_name}}',
                '{{booking_reference}}',
                '{{guest_name}}',
                '{{guest_email}}',
                '{{guest_phone}}',
                '{{room_name}}',
                '{{booked_rate}}', // room invoices / credit notes: the price booked at x nights (empty otherwise)
                '{{check_in_date_formatted}}',
                '{{check_out_date_formatted}}',
                '{{number_of_nights}}',
                '{{number_of_guests}}',
                '{{adult_guests}}',
                '{{child_guests}}',
                '{{total_amount_formatted}}',
                '{{total_amount}}',
                '{{currency_symbol}}',
                '{{contact_email}}',
                '{{contact_phone}}',
                '{{phone_main}}',
                '{{payment_policy}}',
                '{{check_in_time}}',
                '{{check_out_time}}',
                '{{cancellation_reason}}',
                '{{special_requests}}',
                '{{tentative_expires_at_formatted}}',
                '{{quotation_reference}}',
                '{{quote_reference}}',
                '{{valid_until}}',
                '{{quotation_notes}}',
                '{{rate_per_night}}',
                '{{room_subtotal}}',
                '{{vat_amount}}',
                '{{vat_rate}}',
                '{{child_supplement}}',
                '{{deposit_amount}}',
                '{{total_due}}',
                '{{balance_due}}',
                '{{inquiry_reference}}',
                '{{company_name}}',
                '{{contact_person}}',
                '{{conference_room}}',
                '{{event_date}}',
                '{{event_time}}',
                '{{attendees}}',
                '{{event_type}}',
                '{{rate_per_attendee}}',
                '{{recipient_name}}',
                '{{event_title}}',
                '{{event_location}}',
                '{{attendee_count}}',
                '{{check_in_date}}',
                '{{check_out_date}}',
                '{{nights}}',
                '{{guests}}',
                // Credit note placeholders
                '{{credit_note_number}}',
                '{{amount}}',
                '{{balance}}',
                '{{amount_used}}',
                '{{reason}}',
                '{{reason_notes}}',
                '{{expires_at}}',
                '{{logo_html}}',
                '{{invoice_number}}',
                '{{issued_date}}',
                '{{status_text}}',
                '{{client_email}}',
                '{{client_phone}}',
                '{{payment_reference}}',
                '{{payment_date}}',
                '{{payment_method}}',
                '{{payment_type}}',
                '{{payment_status}}',
                '{{payment_amount}}',
                '{{receipt_number}}',
                '{{booking_type}}',
                '{{description}}',
                '{{bank_details_html}}',
                '{{receipt_terms}}',
                '{{address}}',
                '{{hotel_phone}}',
                '{{hotel_address}}',
            ];
            ?>
            <div class="rh-panel settings-card tpl-editor-card" id="email-templates">
                <div class="rh-panel__head"><h2 class="rh-panel__title"><?php echo $mod_bookings ? 'Booking Email &amp; PDF Templates' : 'Email &amp; PDF Templates'; ?></h2></div>
                <p class="help-text">Email tabs preview wrapped emails. PDF tabs preview document HTML and the test-send action emails a real PDF attachment generated from the preview.</p>

                <!-- Tab bar -->
                <div class="tpl-tabs" role="tablist">
                    <?php foreach ($booking_template_defs as $tkey => $tname):
                        $tBadge = $booking_templates[$tkey];
                        $tIsActive  = (int)($tBadge['is_active'] ?? 1) === 1;
                        $tHasContent = !empty($tBadge['html_body']);
                    ?>
                        <button class="tpl-tab <?php echo $tkey === $activeTabKey ? 'active' : ''; ?>"
                            type="button" data-tpl="<?php echo $tkey; ?>" role="tab"
                            aria-selected="<?php echo $tkey === $activeTabKey ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($booking_template_short_names[$tkey] ?? $tname); ?>
                            <span class="tpl-dot <?php echo $tIsActive ? 'dot-active' : 'dot-inactive'; ?>"
                                title="<?php echo $tIsActive ? 'Active' : 'Inactive'; ?>"></span>
                            <?php if (!$tHasContent): ?><span class="tpl-dot dot-warn" title="Not configured"></span><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <form method="POST" id="tpl-main-form" action="<?php echo $rh_self; ?>">
                    <input type="hidden" name="booking_email_templates" value="1">

                    <?php foreach ($booking_template_defs as $tkey => $tname):
                        $tpl      = $booking_templates[$tkey];
                        $isActive = (int)($tpl['is_active'] ?? 1) === 1;
                        $isShown  = ($tkey === $activeTabKey);
                    ?>
                        <div class="tpl-panel" id="panel-<?php echo $tkey; ?>" <?php echo $isShown ? '' : 'hidden'; ?> role="tabpanel">
                            <div class="tpl-split<?php echo $canEditTemplates ? '' : ' tpl-split--locked'; ?>">

                                <!-- ====== EDITOR ====== -->
                                <?php if ($canEditTemplates): ?>
                                <div class="tpl-editor">
                                    <div class="tpl-editor-header">
                                        <span class="tpl-badge <?php echo $isActive ? 'tpl-badge-active' : 'tpl-badge-inactive'; ?>">
                                            <?php echo $isActive ? 'Active' : 'Inactive'; ?>
                                        </span>
                                        <?php if (empty($tpl['html_body'])): ?>
                                            <span class="tpl-badge" style="background:#fef3c7;color:#92400e;">Not configured</span>
                                        <?php endif; ?>
                                        <label class="tpl-active-toggle" title="Toggle template active">
                                            <input type="checkbox" name="<?php echo $tkey; ?>_is_active" value="1" <?php echo $isActive ? 'checked' : ''; ?>>
                                            <span>Active</span>
                                        </label>
                                    </div>

                                    <div class="form-group" style="margin-top:10px;">
                                        <label for="<?php echo $tkey; ?>_subject">Subject line</label>
                                        <input type="text"
                                            id="<?php echo $tkey; ?>_subject"
                                            name="<?php echo $tkey; ?>_subject"
                                            class="form-control"
                                            value="<?php echo htmlspecialchars($tpl['subject'] ?? ''); ?>"
                                            placeholder="e.g. Booking Confirmed — {{site_name}} [{{booking_reference}}]">
                                    </div>

                                    <div class="form-group">
                                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:5px;flex-wrap:wrap;gap:6px;">
                                            <label for="<?php echo $tkey; ?>_html_body" style="margin:0;">HTML body</label>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;">
                                                <button type="button" class="btn-load-default" data-tpl="<?php echo $tkey; ?>">
                                                    <i class="fas fa-undo"></i> Load Default
                                                </button>
                                                <button type="button" class="btn-format-html" data-tpl="<?php echo $tkey; ?>" title="Auto-indent the HTML">
                                                    <i class="fas fa-indent"></i> Format
                                                </button>
                                            </div>
                                        </div>
                                        <textarea id="<?php echo $tkey; ?>_html_body"
                                            name="<?php echo $tkey; ?>_html_body"
                                            class="form-control tpl-html-editor"
                                            rows="18"
                                            spellcheck="false"
                                            autocomplete="off"><?php echo htmlspecialchars($tpl['html_body'] ?? ''); ?></textarea>
                                    </div>

                                    <details class="tpl-textbody-toggle">
                                        <summary>Plain text version <span style="font-weight:400;font-size:10.5px;color:#9ca3af;">(optional fallback for clients that can't render HTML)</span></summary>
                                        <div style="margin-top:8px;">
                                            <textarea id="<?php echo $tkey; ?>_text_body"
                                                name="<?php echo $tkey; ?>_text_body"
                                                class="form-control"
                                                rows="5"
                                                placeholder="Plain text version of this email..."><?php echo htmlspecialchars($tpl['text_body'] ?? ''); ?></textarea>
                                        </div>
                                    </details>

                                    <!-- Placeholder chips -->
                                    <details class="tips-box tpl-placeholders" style="margin-top:10px;">
                                        <summary><i class="fas fa-code"></i> Placeholders <em style="font-weight:400;font-size:10px;margin-left:4px;">— click to insert at cursor</em></summary>
                                        <div class="tips-body">
                                            <div class="placeholder-grid" id="chips-<?php echo $tkey; ?>">
                                                <?php foreach ($allPlaceholders as $ph): ?>
                                                    <code class="tpl-chip" data-tpl="<?php echo $tkey; ?>"><?php echo htmlspecialchars($ph); ?></code>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </details>
                                </div><!-- /tpl-editor -->
                                <?php else: ?>
                                <?php /* No fields to read, so the preview endpoint falls back to the
                                         saved template for this key — see the _ajax_preview handler. */ ?>
                                <div class="tpl-editor tpl-editor--locked">
                                    <p class="help-text" style="margin:0;">
                                        <i class="fas fa-lock" aria-hidden="true"></i>
                                        This is the template guests receive. Editing it needs the
                                        &ldquo;Edit message templates&rdquo; permission &mdash;
                                        use Preview to see how it reads.
                                    </p>
                                </div>
                                <?php endif; ?>

                                <!-- ====== PREVIEW ====== -->
                                <div class="tpl-preview-pane" id="preview-pane-<?php echo $tkey; ?>">
                                    <div class="tpl-preview-toolbar">
                                        <button type="button" class="btn-submit btn-preview btn-ajax-preview" data-tpl="<?php echo $tkey; ?>">
                                            <i class="fas fa-eye"></i> Preview
                                        </button>
                                        <span class="tpl-preview-subject-label" id="preview-subject-<?php echo $tkey; ?>">preview will appear here</span>
                                    </div>

                                    <div class="tpl-preview-wrapper" id="preview-wrapper-<?php echo $tkey; ?>">
                                        <div class="tpl-preview-empty">
                                            <i class="fas fa-envelope-open" style="font-size:36px;color:#D6CDBF;"></i>
                                            <p>Click <strong>Preview</strong> to render this email with sample guest data</p>
                                        </div>
                                    </div>

                                    <div class="tpl-send-test" id="send-test-area-<?php echo $tkey; ?>" style="display:none;">
                                        <p class="tpl-send-test-label"><i class="fas fa-paper-plane"></i> Send as Test Email</p>
                                        <div class="tpl-send-test-row">
                                            <input type="email"
                                                id="test-email-addr-<?php echo $tkey; ?>"
                                                class="form-control tpl-test-email-input"
                                                placeholder="recipient@example.com"
                                                value="<?php echo htmlspecialchars((string)getEmailSetting('email_admin_email', '')); ?>">
                                            <button type="button"
                                                class="btn-submit btn-send-test"
                                                data-tpl="<?php echo $tkey; ?>"
                                                style="white-space:nowrap;background:#5C4A32;">
                                                <i class="fas fa-paper-plane"></i> Send
                                            </button>
                                        </div>
                                        <p class="tpl-send-feedback" id="send-feedback-<?php echo $tkey; ?>" style="display:none;"></p>
                                    </div>
                                </div><!-- /tpl-preview-pane -->

                            </div><!-- /tpl-split -->
                        </div><!-- /tpl-panel -->
                    <?php endforeach; ?>

                    <?php if ($canEditTemplates): ?>
                    <div style="padding-top:14px;border-top:1px solid #e8e3d7;margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Save All Templates
                        </button>
                        <button
                            type="submit"
                            name="reset_all_booking_templates_to_defaults"
                            value="1"
                            class="btn-submit"
                            style="background:#6b7280;"
                            onclick="return confirm('Reset all booking email and PDF templates to the current built-in defaults? This will overwrite all subject lines and HTML bodies.');">
                            <i class="fas fa-rotate-left"></i> Reset All to Defaults
                        </button>
                        <span class="help-text" style="margin:0;color:#6b7280;">Uses the same canonical defaults that power runtime seeding and per-template Load Default.</span>
                    </div>
                    <?php endif; ?>
                </form>
            </div><!-- /tpl-editor-card -->

            <?php
            // WhatsApp wording that goes with a receipt (moved here from the Receipts page; the
            // setting key is unchanged and the Receipts page keeps using it for its WhatsApp links).
            $rcptWaText = (string)getSetting('receipt_whatsapp_template', '');
            $rcptWaCur = (string)getSetting('currency_symbol', 'MWK');
            $rcptWaPreview = [
                '{{site_name}}' => (string)getSetting('site_name', 'Hotel'),
                '{{guest_name}}' => 'Jane Mwale',
                '{{receipt_number}}' => 'RCP-20260521-0042',
                '{{payment_reference}}' => 'PAY-20260521-0874',
                '{{booking_reference}}' => 'BK-2026-1048',
                '{{payment_date}}' => date('d M Y'),
                '{{payment_method}}' => 'Mobile Money',
                '{{payment_type}}' => 'Full Payment',
                '{{payment_status}}' => 'Completed',
                '{{payment_amount}}' => $rcptWaCur . ' 200,000.00',
                '{{vat_amount}}' => $rcptWaCur . ' 45,000.00',
                '{{total_amount}}' => $rcptWaCur . ' 245,000.00',
                '{{description}}' => 'Room booking payment for Deluxe Ocean Suite',
                '{{contact_email}}' => (string)(getEmailSetting('email_from_email', '') ?: 'reservations@example.com'),
            ];
            ?>
            <div class="rh-panel settings-card" id="receipt-whatsapp">
                <div class="rh-panel__head"><h2 class="rh-panel__title">Receipt WhatsApp message</h2></div>
                <p class="help-text">The message pre-filled when staff share a receipt on WhatsApp from the Receipts page. The receipt <em>email</em> is the &ldquo;Receipt Email&rdquo; tab above.</p>
                <p class="help-text">Placeholders: <?php echo htmlspecialchars(implode(', ', array_keys($rcptWaPreview)), ENT_QUOTES, 'UTF-8'); ?>.</p>
                <form method="POST" action="<?php echo $rh_self; ?>" id="receipt-whatsapp-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="save_receipt_whatsapp" value="1">
                    <div class="form-group">
                        <label for="receiptWhatsappTemplate">WhatsApp message</label>
                        <textarea id="receiptWhatsappTemplate" name="receipt_whatsapp_template" rows="3" maxlength="2000" <?php echo $canEditTemplates ? 'required' : 'readonly'; ?> style="width:100%;"><?php echo htmlspecialchars($rcptWaText, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Preview</label>
                        <p id="receiptWhatsappPreview" data-map="<?php echo htmlspecialchars((string)json_encode($rcptWaPreview), ENT_QUOTES, 'UTF-8'); ?>" style="margin:0;padding:10px 12px;border:1px solid #e8e3d7;border-radius:6px;background:#fff;white-space:pre-wrap;font-size:13.5px;"></p>
                    </div>
                    <?php if ($canEditTemplates): ?>
                    <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save WhatsApp message</button>
                    <?php endif; ?>
                </form>
            </div><!-- /receipt-whatsapp -->
            <script>
                (function() {
                    var ta = document.getElementById('receiptWhatsappTemplate');
                    var out = document.getElementById('receiptWhatsappPreview');
                    if (!ta || !out) { return; }
                    var map = {};
                    try { map = JSON.parse(out.dataset.map || '{}') || {}; } catch (e) { map = {}; }
                    function render() {
                        var t = ta.value || '';
                        Object.keys(map).forEach(function(k) { t = t.split(k).join(String(map[k] || '')); });
                        out.textContent = t;
                    }
                    ta.addEventListener('input', render);
                    render();
                })();
            </script>

            <div class="info-box">
                <p style="margin:0;font-size:12.5px;"><i class="fas fa-lock"></i> <strong>SMTP passwords are encrypted in the database.</strong> No credentials are stored in config files.</p>
            </div>

            <script>
                (function() {
                    // Seeded defaults for "Load Default" button
                    var tplDefaults = <?php echo json_encode($tplDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
                    var placeholderTokens = <?php echo json_encode($allPlaceholders, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

                    var previewSectionTarget = document.getElementById('email-templates');
                    if (previewSectionTarget) {
                        var previewSectionParams = new URLSearchParams(window.location.search);
                        if (window.location.hash === '#email-templates' || previewSectionParams.get('section') === 'email-templates') {
                            window.requestAnimationFrame(function() {
                                previewSectionTarget.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            });
                        }
                    }

                    var autocompleteState = {
                        field: null,
                        tokenStart: -1,
                        filtered: [],
                        activeIndex: 0
                    };
                    var autocompleteMenu = document.createElement('div');
                    autocompleteMenu.className = 'tpl-autocomplete';
                    autocompleteMenu.style.display = 'none';
                    document.body.appendChild(autocompleteMenu);

                    function hideAutocomplete() {
                        autocompleteMenu.style.display = 'none';
                        autocompleteMenu.innerHTML = '';
                        autocompleteState.field = null;
                        autocompleteState.tokenStart = -1;
                        autocompleteState.filtered = [];
                        autocompleteState.activeIndex = 0;
                    }

                    function positionAutocomplete(field) {
                        var rect = field.getBoundingClientRect();
                        autocompleteMenu.style.left = (window.scrollX + rect.left) + 'px';
                        autocompleteMenu.style.top = (window.scrollY + rect.bottom + 4) + 'px';
                        autocompleteMenu.style.width = Math.min(rect.width, 420) + 'px';
                    }

                    function updateAutocompleteActiveItem() {
                        var items = autocompleteMenu.querySelectorAll('.tpl-autocomplete__item');
                        items.forEach(function(item, index) {
                            if (index === autocompleteState.activeIndex) {
                                item.classList.add('is-active');
                            } else {
                                item.classList.remove('is-active');
                            }
                        });
                    }

                    function applyAutocompleteSelection(index) {
                        var field = autocompleteState.field;
                        if (!field) {
                            hideAutocomplete();
                            return;
                        }

                        var token = autocompleteState.filtered[index] || autocompleteState.filtered[0];
                        if (!token) {
                            hideAutocomplete();
                            return;
                        }

                        var caret = field.selectionStart;
                        var before = field.value.substring(0, autocompleteState.tokenStart);
                        var after = field.value.substring(caret);
                        field.value = before + token + after;
                        var newPos = before.length + token.length;
                        field.selectionStart = newPos;
                        field.selectionEnd = newPos;
                        field.focus();
                        hideAutocomplete();
                    }

                    function renderAutocomplete(field, filtered, tokenStart) {
                        autocompleteState.field = field;
                        autocompleteState.filtered = filtered;
                        autocompleteState.tokenStart = tokenStart;
                        autocompleteState.activeIndex = 0;

                        autocompleteMenu.innerHTML = '';
                        filtered.forEach(function(token, index) {
                            var btn = document.createElement('button');
                            btn.type = 'button';
                            btn.className = 'tpl-autocomplete__item';
                            if (index === 0) {
                                btn.classList.add('is-active');
                            }
                            btn.textContent = token;
                            btn.addEventListener('mousedown', function(event) {
                                event.preventDefault();
                                applyAutocompleteSelection(index);
                            });
                            autocompleteMenu.appendChild(btn);
                        });

                        positionAutocomplete(field);
                        autocompleteMenu.style.display = 'block';
                    }

                    function refreshAutocomplete(field) {
                        if (!field || typeof field.selectionStart !== 'number') {
                            hideAutocomplete();
                            return;
                        }

                        var caret = field.selectionStart;
                        var beforeCaret = field.value.slice(0, caret);
                        var match = beforeCaret.match(/\{\{[a-zA-Z0-9_]*$/);
                        if (!match) {
                            hideAutocomplete();
                            return;
                        }

                        var partialToken = match[0];
                        var query = partialToken.slice(2).toLowerCase();
                        var tokenStart = caret - partialToken.length;
                        var filtered = placeholderTokens.filter(function(token) {
                            var cleanToken = token.slice(2, -2).toLowerCase();
                            return cleanToken.indexOf(query) !== -1;
                        }).slice(0, 10);

                        if (!filtered.length) {
                            hideAutocomplete();
                            return;
                        }

                        renderAutocomplete(field, filtered, tokenStart);
                    }

                    function bindAutocomplete(field) {
                        field.addEventListener('input', function() {
                            refreshAutocomplete(field);
                        });
                        field.addEventListener('click', function() {
                            refreshAutocomplete(field);
                        });
                        field.addEventListener('keyup', function(event) {
                            if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Enter' || event.key === 'Tab' || event.key === 'Escape') {
                                return;
                            }
                            refreshAutocomplete(field);
                        });
                        field.addEventListener('keydown', function(event) {
                            if (autocompleteMenu.style.display !== 'block' || autocompleteState.field !== field) {
                                return;
                            }

                            if (event.key === 'ArrowDown') {
                                event.preventDefault();
                                autocompleteState.activeIndex = (autocompleteState.activeIndex + 1) % autocompleteState.filtered.length;
                                updateAutocompleteActiveItem();
                                return;
                            }
                            if (event.key === 'ArrowUp') {
                                event.preventDefault();
                                autocompleteState.activeIndex = (autocompleteState.activeIndex - 1 + autocompleteState.filtered.length) % autocompleteState.filtered.length;
                                updateAutocompleteActiveItem();
                                return;
                            }
                            if (event.key === 'Enter' || event.key === 'Tab') {
                                event.preventDefault();
                                applyAutocompleteSelection(autocompleteState.activeIndex);
                                return;
                            }
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                hideAutocomplete();
                            }
                        });
                        field.addEventListener('blur', function() {
                            setTimeout(hideAutocomplete, 120);
                        });
                    }

                    document.querySelectorAll('input[id$="_subject"], textarea[id$="_html_body"], textarea[id$="_text_body"]').forEach(function(field) {
                        bindAutocomplete(field);
                    });
                    window.addEventListener('resize', function() {
                        if (autocompleteState.field) {
                            positionAutocomplete(autocompleteState.field);
                        }
                    });
                    window.addEventListener('scroll', function() {
                        if (autocompleteState.field) {
                            positionAutocomplete(autocompleteState.field);
                        }
                    }, true);

                    // ---- Tab switching ----
                    var tabs = document.querySelectorAll('.tpl-tab');
                    var panels = document.querySelectorAll('.tpl-panel');
                    tabs.forEach(function(tab) {
                        tab.addEventListener('click', function() {
                            tabs.forEach(function(t) {
                                t.classList.remove('active');
                                t.setAttribute('aria-selected', 'false');
                            });
                            panels.forEach(function(p) {
                                p.hidden = true;
                            });
                            this.classList.add('active');
                            this.setAttribute('aria-selected', 'true');
                            var panel = document.getElementById('panel-' + this.dataset.tpl);
                            if (panel) {
                                panel.hidden = false;
                                scheduleTemplatePreview(this.dataset.tpl);
                            }
                        });
                    });

                    // ---- Placeholder click-to-insert ----
                    document.querySelectorAll('.tpl-chip').forEach(function(chip) {
                        chip.title = 'Click to insert at cursor';
                        chip.style.cursor = 'pointer';
                        chip.addEventListener('click', function() {
                            var key = this.dataset.tpl;
                            var ta = document.getElementById(key + '_html_body');
                            if (ta) {
                                var s = ta.selectionStart,
                                    e = ta.selectionEnd,
                                    txt = this.textContent;
                                ta.value = ta.value.substring(0, s) + txt + ta.value.substring(e);
                                ta.selectionStart = ta.selectionEnd = s + txt.length;
                                ta.focus();
                                scheduleTemplatePreview(key);
                            }
                            var orig = this.style.background;
                            this.style.background = '#d1fae5';
                            setTimeout(function() {
                                chip.style.background = orig;
                            }, 500);
                        });
                    });

                    // ---- Build FormData for the current template ----
                    function buildPreviewFd(key) {
                        var fd = new FormData();
                        fd.set('booking_email_template_preview', key);
                        fd.set('_ajax_preview', '1');
                        // Include all template fields so the server has full context
                        document.querySelectorAll('.tpl-panel').forEach(function(panel) {
                            panel.querySelectorAll('input, textarea, select').forEach(function(el) {
                                if (el.name) {
                                    if (el.type === 'checkbox') {
                                        if (el.checked) fd.set(el.name, el.value);
                                    } else fd.set(el.name, el.value);
                                }
                            });
                        });
                        return fd;
                    }

                    function parseJsonResponse(response) {
                        return response.text().then(function(text) {
                            var normalized = String(text || '').replace(/^\uFEFF/, '').trim();
                            var firstBrace = normalized.search(/[\[{]/);
                            if (firstBrace > 0) {
                                normalized = normalized.slice(firstBrace);
                            }
                            return JSON.parse(normalized);
                        });
                    }

                    // ---- AJAX Preview (manual + live typing refresh) ----
                    var previewTimers = {};
                    var previewInFlight = {};
                    var previewPending = {};

                    function renderPreviewResult(key, data) {
                        var wrapper = document.getElementById('preview-wrapper-' + key);
                        var subjectLbl = document.getElementById('preview-subject-' + key);
                        var sendArea = document.getElementById('send-test-area-' + key);
                        var feedback = document.getElementById('send-feedback-' + key);
                        if (!wrapper) return;

                        if (data.success) {
                            wrapper.innerHTML = '';

                            // Show warning banner if preview contains unsaved changes
                            if (data.has_unsaved_changes) {
                                var warningBanner = document.createElement('div');
                                warningBanner.style.cssText = 'background:#FEF3C7;border:1px solid #F59E0B;color:#92400E;padding:12px 16px;margin-bottom:12px;border-radius:6px;font-size:13px;line-height:1.5;';
                                warningBanner.innerHTML = '<i class="fas fa-exclamation-triangle" style="color:#F59E0B;margin-right:8px;"></i><strong>Unsaved Changes:</strong> This preview shows your current edits. Click <strong>Save Template</strong> below to apply these changes to actual emails/PDFs sent to guests.';
                                wrapper.appendChild(warningBanner);
                            }

                            var iframe = document.createElement('iframe');
                            iframe.style.cssText = 'width:100%;min-height:500px;border:none;border-radius:0 0 8px 8px;display:block;';
                            iframe.setAttribute('frameborder', '0');
                            iframe.setAttribute('title', 'Email Preview');
                            wrapper.appendChild(iframe);
                            iframe.contentDocument.open();
                            iframe.contentDocument.write(data.full_html);
                            iframe.contentDocument.close();

                            if (subjectLbl) subjectLbl.textContent = data.subject;
                            if (sendArea) {
                                sendArea.style.display = 'block';
                                sendArea._previewHtml = data.html_body || '';
                                sendArea._previewSubject = data.subject;
                                sendArea._previewTextBody = data.text_body || '';
                                sendArea._previewIsDocument = !!data.is_document;
                                if (feedback) feedback.style.display = 'none';
                            }
                        } else {
                            wrapper.innerHTML = '<p style="color:#dc2626;padding:24px;text-align:center;">' +
                                '<i class="fas fa-exclamation-triangle"></i> ' +
                                (data.error || 'Preview failed') + '</p>';
                        }
                    }

                    function requestTemplatePreview(key, triggerBtn) {
                        var btn = triggerBtn || document.querySelector('.btn-ajax-preview[data-tpl="' + key + '"]');
                        if (previewInFlight[key]) {
                            previewPending[key] = true;
                            return;
                        }

                        previewInFlight[key] = true;
                        if (btn) {
                            btn.disabled = true;
                            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';
                        }

                        fetch(<?php echo json_encode($rh_self); ?>, {
                                method: 'POST',
                                body: buildPreviewFd(key)
                            })
                            .then(function(r) {
                                return parseJsonResponse(r);
                            })
                            .then(function(data) {
                                renderPreviewResult(key, data || {
                                    success: false,
                                    error: 'Preview failed'
                                });
                            })
                            .catch(function() {
                                renderPreviewResult(key, {
                                    success: false,
                                    error: 'Request failed — check your connection.'
                                });
                            })
                            .finally(function() {
                                previewInFlight[key] = false;
                                if (btn) {
                                    btn.disabled = false;
                                    btn.innerHTML = '<i class="fas fa-eye"></i> Preview';
                                }

                                if (previewPending[key]) {
                                    previewPending[key] = false;
                                    requestTemplatePreview(key, null);
                                }
                            });
                    }

                    function scheduleTemplatePreview(key) {
                        if (previewTimers[key]) {
                            clearTimeout(previewTimers[key]);
                        }
                        previewTimers[key] = setTimeout(function() {
                            requestTemplatePreview(key, null);
                        }, 320);
                    }

                    document.querySelectorAll('.btn-ajax-preview').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            requestTemplatePreview(this.dataset.tpl, this);
                        });
                    });

                    document.querySelectorAll('.tpl-panel').forEach(function(panel) {
                        var key = (panel.id || '').replace('panel-', '');
                        if (!key) return;
                        panel.querySelectorAll('input[id$="_subject"], textarea[id$="_html_body"], textarea[id$="_text_body"]').forEach(function(field) {
                            field.addEventListener('input', function() {
                                if (!panel.hidden) {
                                    scheduleTemplatePreview(key);
                                }
                            });
                        });
                    });

                    // ---- Send Test Email ----
                    document.querySelectorAll('.btn-send-test').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var key = this.dataset.tpl;
                            var sendArea = document.getElementById('send-test-area-' + key);
                            var emailIn = document.getElementById('test-email-addr-' + key);
                            var feedback = document.getElementById('send-feedback-' + key);
                            var csrfField = document.querySelector('#tpl-main-form [name="csrf_token"]');
                            var csrfToken = String(window._bsCsrf || (csrfField ? csrfField.value : '') || '').trim();
                            var email = emailIn ? emailIn.value.trim() : '';

                            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                                if (feedback) {
                                    feedback.textContent = 'Enter a valid email address.';
                                    feedback.className = 'tpl-send-feedback tpl-feedback-err';
                                    feedback.style.display = 'block';
                                }
                                return;
                            }
                            if (!csrfToken) {
                                if (feedback) {
                                    feedback.textContent = 'Security token missing. Refresh the page and try again.';
                                    feedback.className = 'tpl-send-feedback tpl-feedback-err';
                                    feedback.style.display = 'block';
                                }
                                return;
                            }
                            var html = sendArea ? sendArea._previewHtml : null;
                            var subject = sendArea ? sendArea._previewSubject : 'Test Email';
                            var textBody = sendArea ? (sendArea._previewTextBody || '') : '';
                            var isDocument = sendArea ? !!sendArea._previewIsDocument : false;
                            if (!html) {
                                if (feedback) {
                                    feedback.textContent = 'Generate a preview first.';
                                    feedback.className = 'tpl-send-feedback tpl-feedback-err';
                                    feedback.style.display = 'block';
                                }
                                return;
                            }

                            var fd = new FormData();
                            fd.set('send_test_email', '1');
                            fd.set('_ajax_send_test', '1');
                            fd.set('csrf_token', csrfToken);
                            fd.set('test_email_address', email);
                            fd.set('test_subject', subject);
                            fd.set('test_html', html);
                            fd.set('test_text_body', textBody);
                            fd.set('test_template_key', key);
                            fd.set('test_is_document', isDocument ? '1' : '0');

                            btn.disabled = true;
                            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                            fetch(<?php echo json_encode($rh_self); ?>, {
                                    method: 'POST',
                                    body: fd
                                })
                                .then(function(r) {
                                    return parseJsonResponse(r);
                                })
                                .then(function(data) {
                                    if (feedback) {
                                        feedback.textContent = data.success ? ('Sent to ' + email + '!') : ('Error: ' + (data.error || 'failed'));
                                        feedback.className = 'tpl-send-feedback ' + (data.success ? 'tpl-feedback-ok' : 'tpl-feedback-err');
                                        feedback.style.display = 'block';
                                    }
                                })
                                .catch(function() {
                                    if (feedback) {
                                        feedback.textContent = 'Network error.';
                                        feedback.className = 'tpl-send-feedback tpl-feedback-err';
                                        feedback.style.display = 'block';
                                    }
                                })
                                .finally(function() {
                                    btn.disabled = false;
                                    btn.innerHTML = '<i class="fas fa-paper-plane"></i> Send';
                                });
                        });
                    });

                    // ---- Load Default ----
                    document.querySelectorAll('.btn-load-default').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var key = this.dataset.tpl;
                            var def = tplDefaults[key];
                            if (!def) return;
                            if (!confirm('Reset "' + key.replace(/_/g, ' ') + '" to the built-in default? Current content will be replaced.')) return;
                            var subjectEl = document.getElementById(key + '_subject');
                            var htmlBodyEl = document.getElementById(key + '_html_body');
                            if (subjectEl) subjectEl.value = def.subject;
                            if (htmlBodyEl) htmlBodyEl.value = def.html;
                            scheduleTemplatePreview(key);
                        });
                    });

                    // ---- Basic HTML format (wrap long lines at tags) ----
                    document.querySelectorAll('.btn-format-html').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            var key = this.dataset.tpl;
                            var ta = document.getElementById(key + '_html_body');
                            if (!ta || !ta.value.trim()) return;
                            // Simple: add newline after each closing tag
                            var formatted = ta.value
                                .replace(/>\s*</g, '>\n<')
                                .replace(/\n{3,}/g, '\n\n')
                                .trim();
                            ta.value = formatted;
                            scheduleTemplatePreview(key);
                        });
                    });

                    if (tabs.length > 0) {
                        scheduleTemplatePreview(tabs[0].dataset.tpl);
                    }

                })();
            </script>
            <?php endif; /* $rh_templates_only: template editor */ ?>

            </div><!-- /.content -->

            <?php require_once 'includes/admin-footer.php'; ?>

