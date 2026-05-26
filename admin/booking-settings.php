<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */
require_once __DIR__ . '/../includes/booking-functions.php';
require_once __DIR__ . '/../config/email.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$message = '';
$error = '';
$template_preview = null;
$default_site_maintenance_message = 'Our website is temporarily unavailable while we complete scheduled maintenance. Please check back shortly.';

// Handle enable/disable via GET parameter
if (isset($_GET['enable'])) {
    updateSetting('booking_system_enabled', '1');
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

// Handle one-switch booking system toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_booking_system'])) {
    $enabled = isset($_POST['booking_system_enabled']) ? '1' : '0';
    updateSetting('booking_system_enabled', $enabled);
    $message = $enabled === '1'
        ? 'Booking system enabled successfully!'
        : 'Booking system disabled successfully!';
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

// Handle one-click full frontend maintenance mode toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_site_maintenance'])) {
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

// Save custom maintenance message shown during maintenance mode
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_site_maintenance_message'])) {
    $maintenanceMessageInput = trim((string)($_POST['site_maintenance_message'] ?? ''));
    if ($maintenanceMessageInput === '') {
        $maintenanceMessageInput = $default_site_maintenance_message;
    }

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

// Handle disable via POST
if (isset($_POST['disable_booking'])) {
    updateSetting('booking_system_enabled', '0');
    $message = "Booking system disabled successfully!";
}

// Handle disabled mode settings
if (isset($_POST['booking_disabled_action'])) {
    updateSetting('booking_disabled_action', $_POST['booking_disabled_action']);
    updateSetting('booking_disabled_message', $_POST['booking_disabled_message'] ?? '');
    if (isset($_POST['booking_disabled_redirect_url'])) {
        updateSetting('booking_disabled_redirect_url', $_POST['booking_disabled_redirect_url']);
    }
    $message = "Disabled mode settings updated successfully!";
}

// Get booking system settings
$booking_enabled = isBookingEnabled();
$disabled_action = getBookingDisabledAction();
$disabled_message = getBookingDisabledMessage();
$disabled_redirect_url = getSetting('booking_disabled_redirect_url', '/');
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
        $ajaxValidKeys = [
            'booking_received',
            'booking_confirmed',
            'booking_cancelled',
            'payment_invoice',
            'payment_invoice_document',
            'tentative_booking_created',
            'tentative_booking_reminder',
            'tentative_booking_expired',
            'tentative_booking_converted',
            'tentative_quotation',
            'conference_quotation',
            'event_quotation',
            'credit_note',
        ];
        if (!in_array($ajaxKey, $ajaxValidKeys, true)) {
            throw new Exception('Invalid template key');
        }

        $ajaxSubject  = trim((string)($_POST[$ajaxKey . '_subject']   ?? ''));
        $ajaxHtmlBody = trim((string)($_POST[$ajaxKey . '_html_body'] ?? ''));
        $ajaxTextBody = trim((string)($_POST[$ajaxKey . '_text_body'] ?? ''));

        if ($ajaxSubject === '' || $ajaxHtmlBody === '') {
            $existing = function_exists('getBookingEmailTemplateConfig') ? getBookingEmailTemplateConfig($ajaxKey, []) : [];
            if (!empty($existing['subject'])) {
                $ajaxSubject  = $existing['subject'];
            }
            if (!empty($existing['html_body'])) {
                $ajaxHtmlBody = $existing['html_body'];
            }
            if ($ajaxTextBody === '' && !empty($existing['text_body'])) {
                $ajaxTextBody = $existing['text_body'];
            }
        }
        if ($ajaxSubject === '' || $ajaxHtmlBody === '') {
            throw new Exception('No content to preview — fill in the fields or save the template first.');
        }

        $ajaxVars = [
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
            '{{total_amount}}'             => (string)getSetting('currency_symbol', 'ZAR') . number_format(4500, 2),
            '{{currency_symbol}}'          => (string)getSetting('currency_symbol', 'ZAR'),
            '{{contact_email}}'            => (string)getSetting('email_from_email', getSetting('contact_email', 'reservations@example.com')),
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
            '{{rate_per_night}}'           => (string)getSetting('currency_symbol', 'ZAR') . number_format(2250, 2),
            '{{room_subtotal}}'            => (string)getSetting('currency_symbol', 'ZAR') . number_format(4500, 2),
            '{{vat_amount}}'               => (string)getSetting('currency_symbol', 'ZAR') . number_format(675, 2),
            '{{vat_rate}}'                 => '15',
            '{{child_supplement}}'         => (string)getSetting('currency_symbol', 'ZAR') . '0',
            '{{deposit_amount}}'           => (string)getSetting('currency_symbol', 'ZAR') . number_format(1000, 2),
            '{{balance_due}}'              => (string)getSetting('currency_symbol', 'ZAR') . number_format(3500, 2),
            '{{credit_note_number}}'       => 'CN-RBH-2026-001',
            '{{amount}}'                   => (string)getSetting('currency_symbol', 'ZAR') . number_format(1200, 2),
            '{{balance}}'                  => (string)getSetting('currency_symbol', 'ZAR') . number_format(850, 2),
            '{{amount_used}}'              => (string)getSetting('currency_symbol', 'ZAR') . number_format(350, 2),
            '{{reason}}'                   => 'Overpayment adjustment',
            '{{reason_notes}}'             => 'Issued after reservation amount was corrected.',
            '{{expires_at}}'               => date('F j, Y', strtotime('+90 days')),
            '{{hotel_phone}}'              => (string)getSetting('phone_main', ''),
            '{{hotel_address}}'            => (string)getSetting('hotel_address', getSetting('address', 'Beachfront Road, Cape Maclear')),
        ];

        $ajaxResSubject  = strtr($ajaxSubject,  $ajaxVars);
        $ajaxResHtmlBody = strtr($ajaxHtmlBody, $ajaxVars);
        $ajaxResTextBody = strtr($ajaxTextBody, $ajaxVars);
        $ajaxFullHtml    = function_exists('wrapEmailTemplate')
            ? wrapEmailTemplate($ajaxResHtmlBody, $ajaxResSubject)
            : $ajaxResHtmlBody;

        echo json_encode(['success' => true, 'full_html' => $ajaxFullHtml, 'subject' => $ajaxResSubject, 'text_body' => $ajaxResTextBody]);
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
        if (!filter_var($ajaxTestEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address');
        }
        if (empty($ajaxTestHtml)) {
            throw new Exception('No preview HTML to send');
        }
        if (!function_exists('sendEmail')) {
            throw new Exception('Email sending not available');
        }
        $result = sendEmail($ajaxTestEmail, $ajaxTestEmail, '[TEST] ' . $ajaxTestSubject, $ajaxTestHtml);
        if (!empty($result['success'])) {
            echo json_encode(['success' => true, 'message' => "Test email sent to {$ajaxTestEmail}"]);
        } else {
            throw new Exception($result['message'] ?? 'Unknown error');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// Handle setting updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $stmt = $pdo->prepare("UPDATE site_settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'max_advance_booking_days'");
            $stmt->execute([$max_advance_days]);

            // Clear the setting cache (both in-memory and file cache)
            global $_SITE_SETTINGS;
            if (isset($_SITE_SETTINGS['max_advance_booking_days'])) {
                unset($_SITE_SETTINGS['max_advance_booking_days']);
            }
            // Clear the file cache
            deleteCache("setting_max_advance_booking_days");

            $message = "Maximum advance booking days updated to {$max_advance_days} days successfully!";
        } elseif (isset($_POST['tourism_levy_settings'])) {
            // Tourism levy settings
            $tourism_levy_enabled = isset($_POST['tourism_levy_enabled']) ? '1' : '0';
            $tourism_levy_percent = (float)($_POST['tourism_levy_percent'] ?? 0);

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

            $message = "Booking notification email updated successfully!";
        } elseif (isset($_POST['service_channel_settings'])) {
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
            updateSetting('email_restaurant', $email_restaurant);

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

            $templateDefs = [
                'booking_received'      => 'Booking Received (Customer)',
                'booking_confirmed'     => 'Booking Confirmed (Customer)',
                'booking_cancelled'     => 'Booking Cancelled (Customer)',
                'payment_invoice'       => 'Payment Invoice (Customer)',
                'payment_invoice_document' => 'Invoice Document (PDF Attachment)',
                'tentative_booking_created' => 'Tentative Booking Created',
                'tentative_booking_reminder' => 'Tentative Booking Reminder',
                'tentative_booking_expired' => 'Tentative Booking Expired',
                'tentative_booking_converted' => 'Tentative Booking Converted',
                'tentative_quotation'   => 'Tentative Booking — Quotation',
                'conference_quotation'  => 'Conference Quotation',
                'event_quotation'       => 'Event Quotation',
                'credit_note'           => 'Credit Note (Guest)',
            ];

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

            $currencySymbol = (string)getSetting('currency_symbol', 'ZAR');
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
                '{{contact_email}}'            => (string)getSetting('email_from_email', getSetting('contact_email', 'reservations@example.com')),
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
                '{{credit_note_number}}'       => 'CN-RBH-2026-001',
                '{{amount}}'                   => $currencySymbol . number_format(1200, 2),
                '{{balance}}'                  => $currencySymbol . number_format(850, 2),
                '{{amount_used}}'              => $currencySymbol . number_format(350, 2),
                '{{reason}}'                   => 'Overpayment adjustment',
                '{{reason_notes}}'             => 'Issued after reservation amount was corrected.',
                '{{expires_at}}'               => date('F j, Y', strtotime('+90 days')),
                '{{hotel_phone}}'              => (string)getSetting('phone_main', ''),
                '{{hotel_address}}'            => (string)getSetting('hotel_address', getSetting('address', 'Beachfront Road, Cape Maclear')),
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
            $result = sendEmail($testEmail, $testEmail, '[TEST] ' . $testSubject, $testHtml);
            if (!empty($result['success'])) {
                $message = "Test email sent to {$testEmail} successfully!";
            } else {
                throw new Exception('Failed to send test email: ' . ($result['message'] ?? 'Unknown error'));
            }
        } elseif (isset($_POST['booking_email_templates'])) {
            if (!function_exists('upsertBookingEmailTemplateConfig')) {
                throw new Exception('Booking template storage is not available');
            }

            $templateDefs = [
                'booking_received'    => 'Booking Received (Customer)',
                'booking_confirmed'   => 'Booking Confirmed (Customer)',
                'booking_cancelled'   => 'Booking Cancelled (Customer)',
                'payment_invoice'     => 'Payment Invoice (Customer)',
                'payment_invoice_document' => 'Invoice Document (PDF Attachment)',
                'tentative_booking_created' => 'Tentative Booking Created',
                'tentative_booking_reminder' => 'Tentative Booking Reminder',
                'tentative_booking_expired' => 'Tentative Booking Expired',
                'tentative_booking_converted' => 'Tentative Booking Converted',
                'tentative_quotation' => 'Tentative Booking — Quotation',
                'conference_quotation' => 'Conference Quotation',
                'event_quotation' => 'Event Quotation',
            ];

            foreach ($templateDefs as $templateKey => $templateName) {
                $subject = trim($_POST[$templateKey . '_subject'] ?? '');
                $htmlBody = trim($_POST[$templateKey . '_html_body'] ?? '');
                $textBody = trim($_POST[$templateKey . '_text_body'] ?? '');
                $isActive = isset($_POST[$templateKey . '_is_active']) ? 1 : 0;

                if ($subject === '' || $htmlBody === '') {
                    throw new Exception("{$templateName}: subject and HTML body are required");
                }

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
            ];

            if (!in_array($email_settings['smtp_secure'], ['ssl', 'tls', ''], true)) {
                $email_settings['smtp_secure'] = 'ssl';
            }

            // Validate required fields
            $required_fields = ['smtp_host', 'smtp_port', 'smtp_username', 'email_from_name', 'email_from_email'];
            foreach ($required_fields as $field) {
                if (empty($email_settings[$field])) {
                    throw new Exception(ucfirst(str_replace('_', ' ', $field)) . ' is required');
                }
            }

            // Validate port
            if (!is_numeric($email_settings['smtp_port']) || $email_settings['smtp_port'] < 1 || $email_settings['smtp_port'] > 65535) {
                throw new Exception('SMTP port must be a valid port number (1-65535)');
            }

            // Validate emails
            if (!filter_var($email_settings['email_from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('From email address is invalid');
            }

            if (!empty($email_settings['email_admin_email']) && !filter_var($email_settings['email_admin_email'], FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Admin email address is invalid');
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
    }
}

// Get current setting
$current_max_days = (int)getSetting('max_advance_booking_days', 30);
$current_booking_notification_email = getSetting('booking_notification_email', getSetting('admin_notification_email', ''));
$current_booking_notification_cc_emails = getSetting('booking_notification_cc_emails', '');

$current_conference_system_enabled = getSetting('conference_system_enabled', '1') === '1';
$current_gym_system_enabled = getSetting('gym_system_enabled', '1') === '1';
$current_restaurant_system_enabled = getSetting('restaurant_system_enabled', '1') === '1';

$current_conference_email = getSetting('conference_email', getSetting('email_reservations', ''));
$current_gym_email = getSetting('gym_email', getSetting('email_reservations', ''));
$current_restaurant_email = getSetting('email_restaurant', getSetting('email_reservations', ''));

$booking_template_defs = [
    'booking_received'    => 'Booking Received (Customer)',
    'booking_confirmed'   => 'Booking Confirmed (Customer)',
    'booking_cancelled'   => 'Booking Cancelled (Customer)',
    'payment_invoice'     => 'Payment Invoice (Customer)',
    'payment_invoice_document' => 'Invoice Document (PDF Attachment)',
    'tentative_booking_created' => 'Tentative Booking Created',
    'tentative_booking_reminder' => 'Tentative Booking Reminder',
    'tentative_booking_expired' => 'Tentative Booking Expired',
    'tentative_booking_converted' => 'Tentative Booking Converted',
    'tentative_quotation' => 'Tentative Booking — Quotation',
    'conference_quotation' => 'Conference Quotation',
    'event_quotation' => 'Event Quotation',
    'credit_note'         => 'Credit Note (Guest)',
];

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

// Built-in defaults for the "Load Default" button
$tplDefaults = [
    'booking_received' => [
        'subject' => 'Booking Received - {{site_name}} [{{booking_reference}}]',
        'html'    => '<h1 style="color:#1A1A1A;text-align:center;">Booking Received — Awaiting Confirmation</h1>
<p>Dear {{guest_name}},</p>
<p>Thank you for your booking request with <strong>{{site_name}}</strong>. We have received your booking and it is now pending confirmation.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Reference</td><td style="padding:8px;border:1px solid #e0e0e0;">{{booking_reference}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Room</td><td style="padding:8px;border:1px solid #e0e0e0;">{{room_name}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-in</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_in_date_formatted}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-out</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_out_date_formatted}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Nights</td><td style="padding:8px;border:1px solid #e0e0e0;">{{number_of_nights}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Guests</td><td style="padding:8px;border:1px solid #e0e0e0;">{{number_of_guests}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Total</td><td style="padding:8px;border:1px solid #e0e0e0;">{{currency_symbol}} {{total_amount_formatted}}</td></tr>
</table>
<p>{{payment_policy}}</p>
<p>If you have any questions, please contact us at <a href="mailto:{{contact_email}}">{{contact_email}}</a> or call {{phone_main}}.</p>',
    ],
    'booking_confirmed' => [
        'subject' => 'Booking Confirmed - {{site_name}} [{{booking_reference}}]',
        'html'    => '<h1 style="color:#1A1A1A;text-align:center;">Your Booking is Confirmed!</h1>
<p>Dear {{guest_name}},</p>
<p>We are delighted to confirm your booking at <strong>{{site_name}}</strong>. We look forward to welcoming you.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Reference</td><td style="padding:8px;border:1px solid #e0e0e0;">{{booking_reference}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Room</td><td style="padding:8px;border:1px solid #e0e0e0;">{{room_name}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-in</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_in_date_formatted}} from {{check_in_time}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-out</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_out_date_formatted}} by {{check_out_time}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Nights</td><td style="padding:8px;border:1px solid #e0e0e0;">{{number_of_nights}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Guests</td><td style="padding:8px;border:1px solid #e0e0e0;">{{number_of_guests}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Total</td><td style="padding:8px;border:1px solid #e0e0e0;">{{currency_symbol}} {{total_amount_formatted}}</td></tr>
</table>
<p>{{payment_policy}}</p>
<p>Questions? <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
    ],
    'booking_cancelled' => [
        'subject' => 'Booking Cancelled - {{site_name}} [{{booking_reference}}]',
        'html'    => '<h1 style="color:#dc3545;text-align:center;">Booking Cancelled</h1>
<p>Dear {{guest_name}},</p>
<p>We regret to inform you that your booking with <strong>{{site_name}}</strong> has been cancelled.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Reference</td><td style="padding:8px;border:1px solid #e0e0e0;">{{booking_reference}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Room</td><td style="padding:8px;border:1px solid #e0e0e0;">{{room_name}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-in</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_in_date_formatted}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-out</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_out_date_formatted}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Reason</td><td style="padding:8px;border:1px solid #e0e0e0;">{{cancellation_reason}}</td></tr>
</table>
<p>If you believe this is an error or would like to make a new booking, please contact us at <a href="mailto:{{contact_email}}">{{contact_email}}</a> or {{phone_main}}.</p>',
    ],
    'payment_invoice' => [
        'subject' => 'Payment Invoice - {{site_name}} [{{booking_reference}}]',
        'html'    => '<h1 style="color:#1A1A1A;text-align:center;">Payment Confirmed</h1>
<p>Dear {{guest_name}},</p>
<p>Thank you — your payment has been received for booking <strong>{{booking_reference}}</strong> at <strong>{{site_name}}</strong>.</p>
<table style="width:100%;border-collapse:collapse;margin:16px 0;">
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Reference</td><td style="padding:8px;border:1px solid #e0e0e0;">{{booking_reference}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Room</td><td style="padding:8px;border:1px solid #e0e0e0;">{{room_name}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-in</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_in_date_formatted}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Check-out</td><td style="padding:8px;border:1px solid #e0e0e0;">{{check_out_date_formatted}}</td></tr>
  <tr><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;">Amount Paid</td><td style="padding:8px;border:1px solid #e0e0e0;font-weight:bold;color:#065f46;">{{currency_symbol}} {{total_amount_formatted}}</td></tr>
</table>
<p>Your invoice is attached to this email.</p>
<p>Thank you for choosing {{site_name}}. We look forward to welcoming you on {{check_in_date_formatted}}.</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
    ],
    'payment_invoice_document' => [
        'subject' => 'Invoice Document',
        'html'    => '<div style="font-family:Georgia,\'Times New Roman\',serif; color:#231F1C; background:#FFFFFF; max-width:680px; margin:0 auto;"><table style="width:100%; background:#231F1C; margin-bottom:0;" cellpadding="0" cellspacing="0"><tr><td style="padding:28px 30px 22px; vertical-align:middle; width:50%;">{{logo_html}}<p style="margin:8px 0 0; font-size:11px; letter-spacing:3px; text-transform:uppercase; color:#B18247; font-family:Helvetica,Arial,sans-serif;">{{site_name}}</p></td><td style="padding:28px 30px 22px; vertical-align:middle; text-align:right; width:50%;"><p style="margin:0; font-size:30px; font-weight:400; letter-spacing:5px; color:#F3ECE4; font-family:Georgia,serif;">INVOICE</p><p style="margin:6px 0 0; font-size:12px; letter-spacing:1px; color:#B18247; font-family:Helvetica,Arial,sans-serif;">{{invoice_number}}</p><p style="margin:4px 0 0; font-size:10px; color:#8A775F; font-family:Helvetica,Arial,sans-serif;">Issued {{issued_date}}</p></td></tr></table><table style="width:100%; background:{{status_bg}};" cellpadding="0" cellspacing="0"><tr><td style="padding:9px 30px; font-family:Helvetica,Arial,sans-serif; font-size:10px; letter-spacing:3px; font-weight:700; color:{{status_fg}}; text-transform:uppercase; text-align:right;">{{status_text}}</td></tr></table><table style="width:100%; background:#F7F3EE;" cellpadding="0" cellspacing="0"><tr><td style="padding:20px 24px; width:50%; vertical-align:top;"><p style="margin:0 0 6px 0; font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#B18247;">Billed To</p><p style="margin:0 0 3px; font-size:15px; font-weight:700; color:#231F1C; font-family:Georgia,serif;">{{guest_name}}</p><p style="margin:0 0 2px; font-size:11px; color:#5E554D; font-family:Helvetica,Arial,sans-serif;">{{guest_email}}</p><p style="margin:0; font-size:11px; color:#5E554D; font-family:Helvetica,Arial,sans-serif;">{{guest_phone}}</p></td><td style="padding:20px 24px; width:50%; vertical-align:top;"><p style="margin:0 0 6px 0; font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#B18247;">Property</p><p style="margin:0 0 3px; font-size:15px; font-weight:700; color:#231F1C; font-family:Georgia,serif;">{{site_name}}</p><p style="margin:0 0 2px; font-size:11px; color:#5E554D; font-family:Helvetica,Arial,sans-serif;">{{address}}</p><p style="margin:0 0 2px; font-size:11px; color:#5E554D; font-family:Helvetica,Arial,sans-serif;">{{contact_email}}</p><p style="margin:0; font-size:11px; color:#5E554D; font-family:Helvetica,Arial,sans-serif;">{{contact_phone}}</p>{{vat_number_html}}</td></tr></table><table style="width:100%; background:#231F1C;" cellpadding="0" cellspacing="0"><tr><td style="padding:14px 30px; font-family:Helvetica,Arial,sans-serif;"><table style="width:100%;" cellpadding="0" cellspacing="0"><tr><td style="font-size:9px; color:#C4A882; letter-spacing:2px; text-transform:uppercase; padding-bottom:3px;">Reference</td><td style="font-size:9px; color:#C4A882; letter-spacing:2px; text-transform:uppercase; padding-bottom:3px;">Room</td><td style="font-size:9px; color:#C4A882; letter-spacing:2px; text-transform:uppercase; padding-bottom:3px;">Check-in</td><td style="font-size:9px; color:#C4A882; letter-spacing:2px; text-transform:uppercase; padding-bottom:3px;">Check-out</td><td style="font-size:9px; color:#C4A882; letter-spacing:2px; text-transform:uppercase; padding-bottom:3px;">Nights</td><td style="font-size:9px; color:#C4A882; letter-spacing:2px; text-transform:uppercase; padding-bottom:3px;">Guests</td></tr><tr><td style="font-size:12px; color:#B18247; font-weight:700;">{{booking_reference}}</td><td style="font-size:12px; color:#FFFFFF; font-weight:500;">{{room_name}}</td><td style="font-size:12px; color:#FFFFFF; font-weight:500;">{{check_in}}</td><td style="font-size:12px; color:#FFFFFF; font-weight:500;">{{check_out}}</td><td style="font-size:12px; color:#FFFFFF; font-weight:500;">{{nights}}</td><td style="font-size:12px; color:#FFFFFF; font-weight:500;">{{guests}}</td></tr></table></td></tr></table><p style="margin:16px 24px 6px; font-size:9px; font-weight:700; letter-spacing:2px; text-transform:uppercase; color:#B18247; font-family:Helvetica,Arial,sans-serif;">Itemised Charges</p><table width="100%" style="width:100%; border-collapse:collapse;" cellpadding="0" cellspacing="0"><tr><td width="52%" bgcolor="#8A775F" style="padding:12px 24px; text-align:left; font-size:10px; letter-spacing:1.5px; text-transform:uppercase; color:#FFFFFF; font-family:Helvetica,Arial,sans-serif; font-weight:700; background-color:#8A775F;">Description</td><td width="10%" bgcolor="#8A775F" style="padding:12px 10px; text-align:center; font-size:10px; letter-spacing:1.5px; text-transform:uppercase; color:#FFFFFF; font-family:Helvetica,Arial,sans-serif; font-weight:700; background-color:#8A775F;">Qty</td><td width="18%" bgcolor="#8A775F" style="padding:12px 10px; text-align:right; font-size:10px; letter-spacing:1.5px; text-transform:uppercase; color:#FFFFFF; font-family:Helvetica,Arial,sans-serif; font-weight:700; background-color:#8A775F;">Unit Price</td><td width="20%" bgcolor="#8A775F" style="padding:12px 24px; text-align:right; font-size:10px; letter-spacing:1.5px; text-transform:uppercase; color:#FFFFFF; font-family:Helvetica,Arial,sans-serif; font-weight:700; background-color:#8A775F;">Amount</td></tr>{{charges_table_rows}}{{totals_rows}}</table>{{payment_history_section}}<table style="width:100%; margin-top:28px; background:#F7F3EE;" cellpadding="0" cellspacing="0"><tr><td style="padding:20px 30px; border-top:2px solid #B18247; text-align:center; font-family:Helvetica,Arial,sans-serif;"><p style="margin:0 0 4px; font-size:13px; font-weight:700; color:#231F1C; letter-spacing:1px;">{{site_name}}</p><p style="margin:0 0 3px; font-size:10px; color:#8A775F;">{{address}}</p><p style="margin:0 0 12px; font-size:10px; color:#8A775F;">{{contact_email}} &nbsp;|&nbsp; {{contact_phone}}</p><p style="margin:0; font-size:9px; color:#B18247; letter-spacing:2px; text-transform:uppercase;">Thank you for choosing us</p></td></tr></table></div>',
    ],
    'tentative_booking_created' => [
        'subject' => 'Tentative Booking Created - {{site_name}} [{{booking_reference}}]',
        'html'    => '<h1 style="color:#8B7355;text-align:center;">Tentative Booking Created</h1>
<p>Dear {{guest_name}},</p>
<p>Your room has been placed on tentative hold.</p>
<p><strong>Reference:</strong> {{booking_reference}}<br>
<strong>Room:</strong> {{room_name}}<br>
<strong>Check-in:</strong> {{check_in_date_formatted}}<br>
<strong>Check-out:</strong> {{check_out_date_formatted}}<br>
<strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}<br>
<strong>Hold Expires:</strong> {{tentative_expires_at_formatted}}</p>
<p>Please confirm before the hold expiry date.</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
    ],
    'tentative_booking_reminder' => [
        'subject' => 'Reminder: Tentative Booking Expires Soon - {{booking_reference}}',
        'html'    => '<h1 style="color:#8B7355;text-align:center;">Tentative Booking Reminder</h1>
<p>Dear {{guest_name}},</p>
<p>Your tentative hold expires soon.</p>
<p><strong>Reference:</strong> {{booking_reference}}<br>
<strong>Room:</strong> {{room_name}}<br>
<strong>Hold Expires:</strong> {{tentative_expires_at_formatted}}</p>
<p>Reply to this email to confirm your booking.</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
    ],
    'tentative_booking_expired' => [
        'subject' => 'Tentative Booking Expired - {{booking_reference}}',
        'html'    => '<h1 style="color:#6c757d;text-align:center;">Tentative Booking Expired</h1>
<p>Dear {{guest_name}},</p>
<p>Your tentative hold has expired and the room is now available again.</p>
<p><strong>Reference:</strong> {{booking_reference}}<br>
<strong>Room:</strong> {{room_name}}<br>
<strong>Check-in:</strong> {{check_in_date_formatted}}<br>
<strong>Check-out:</strong> {{check_out_date_formatted}}</p>
<p>You can create a new booking at any time.</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
    ],
    'tentative_booking_converted' => [
        'subject' => 'Booking Confirmed - {{site_name}} [{{booking_reference}}]',
        'html'    => '<h1 style="color:#8B7355;text-align:center;">Booking Confirmed</h1>
<p>Dear {{guest_name}},</p>
<p>Your tentative booking has been confirmed.</p>
<p><strong>Reference:</strong> {{booking_reference}}<br>
<strong>Room:</strong> {{room_name}}<br>
<strong>Check-in:</strong> {{check_in_date_formatted}} from {{check_in_time}}<br>
<strong>Check-out:</strong> {{check_out_date_formatted}} by {{check_out_time}}<br>
<strong>Total:</strong> {{currency_symbol}} {{total_amount_formatted}}</p>
<p>{{payment_policy}}</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{phone_main}}</p>',
    ],
    'tentative_quotation' => [
        'subject' => 'Quotation for Your Stay — {{site_name}} [{{quotation_reference}}]',
        'html'    => '<h1 style="color:#8B7355;text-align:center;font-family:Georgia,serif;font-weight:400;letter-spacing:1px;">Hotel Quotation</h1>
<p style="font-family:Tahoma,Verdana,sans-serif;font-size:15px;color:#333;">Dear {{guest_name}},</p>
<p style="font-family:Tahoma,Verdana,sans-serif;font-size:15px;color:#333;">Thank you for your enquiry. Please find below your formal quotation for your upcoming stay at <strong>{{site_name}}</strong>. A detailed PDF is attached for your reference.</p>
<div style="background:#FAF6F0;border:2px solid #C8A45A;border-radius:8px;padding:18px 22px;margin:20px 0;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr>
<td style="font-family:Tahoma,Verdana,sans-serif;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;margin-bottom:3px;">Quotation Reference</div><div style="font-size:20px;font-weight:600;color:#8B7355;">{{quotation_reference}}</div></td>
<td align="right" style="font-family:Tahoma,Verdana,sans-serif;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:1px;color:#999;margin-bottom:3px;">Valid Until</div><div style="font-size:16px;font-weight:600;color:#C0392B;">{{valid_until}}</div></td>
</tr></table></div>
<div style="background:#FAF6F0;border-radius:8px;padding:18px 22px;margin:20px 0;">
<h2 style="color:#8B7355;font-family:Georgia,serif;font-weight:400;margin-top:0;font-size:18px;">Stay Details</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:14px;border-bottom:1px solid #EDE8E0;width:45%;">Room</td><td style="padding:8px 0;font-size:14px;font-weight:600;color:#1A1A1A;border-bottom:1px solid #EDE8E0;">{{room_name}}</td></tr>
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:14px;border-bottom:1px solid #EDE8E0;">Check-in</td><td style="padding:8px 0;font-size:14px;font-weight:600;color:#1A1A1A;border-bottom:1px solid #EDE8E0;">{{check_in_date}} &mdash; from {{check_in_time}}</td></tr>
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:14px;border-bottom:1px solid #EDE8E0;">Check-out</td><td style="padding:8px 0;font-size:14px;font-weight:600;color:#1A1A1A;border-bottom:1px solid #EDE8E0;">{{check_out_date}} &mdash; by {{check_out_time}}</td></tr>
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:14px;border-bottom:1px solid #EDE8E0;">Duration</td><td style="padding:8px 0;font-size:14px;font-weight:600;color:#1A1A1A;border-bottom:1px solid #EDE8E0;">{{nights}} night(s)</td></tr>
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:14px;border-bottom:1px solid #EDE8E0;">Guests</td><td style="padding:8px 0;font-size:14px;font-weight:600;color:#1A1A1A;border-bottom:1px solid #EDE8E0;">{{guests}}</td></tr>
</table></div>
<div style="background:#FFF;border:1px solid #DDD;border-radius:8px;padding:18px 22px;margin:20px 0;">
<h2 style="color:#8B7355;font-family:Georgia,serif;font-weight:400;margin-top:0;font-size:18px;">Pricing</h2>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0">
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:14px;border-bottom:1px solid #EDE8E0;width:55%;">{{room_name}} &times; {{nights}} night(s) @ {{rate_per_night}}/night</td><td style="padding:8px 0;font-size:14px;color:#1A1A1A;border-bottom:1px solid #EDE8E0;">{{room_subtotal}}</td></tr>
<tr><td style="padding:8px 12px 8px 0;color:#555;font-size:15px;font-weight:600;border-bottom:1px solid #EDE8E0;">Total Amount</td><td style="padding:8px 0;font-size:15px;font-weight:700;color:#8B7355;border-bottom:1px solid #EDE8E0;">{{total_amount}}</td></tr>
</table></div>
<div style="background:#FFF8DC;border-left:4px solid #F0A500;border-radius:4px;padding:15px 18px;margin:20px 0;">
<p style="color:#856404;font-family:Tahoma,Verdana,sans-serif;font-size:14px;margin:0;font-weight:600;">Payment Terms</p>
<p style="color:#856404;font-family:Tahoma,Verdana,sans-serif;font-size:14px;margin:6px 0 0;">{{payment_policy}}</p></div>
<div style="background:#F0F7FF;border-left:4px solid #4A90D9;border-radius:4px;padding:15px 18px;margin:20px 0;">
<p style="color:#1A4A8A;font-family:Tahoma,Verdana,sans-serif;font-size:14px;margin:0;font-weight:600;">Note from our team</p>
<p style="color:#1A4A8A;font-family:Tahoma,Verdana,sans-serif;font-size:14px;margin:6px 0 0;">{{quotation_notes}}</p></div>
<div style="text-align:center;margin:30px 0 20px;">
<p style="font-family:Tahoma,Verdana,sans-serif;font-size:14px;color:#555;margin-bottom:16px;">To confirm this reservation, simply reply to this email or contact us directly.</p>
<a href="mailto:{{contact_email}}" style="display:inline-block;background:#8B7355;color:#FFF;padding:13px 30px;text-decoration:none;border-radius:4px;font-family:Tahoma,Verdana,sans-serif;font-size:15px;font-weight:600;">Confirm My Booking</a>
<p style="font-family:Tahoma,Verdana,sans-serif;font-size:13px;color:#888;margin-top:14px;">Or call us: <a href="tel:{{contact_phone}}" style="color:#8B7355;">{{contact_phone}}</a></p>
</div>
<p style="font-family:Tahoma,Verdana,sans-serif;font-size:13px;color:#999;text-align:center;">This quotation is valid until <strong>{{valid_until}}</strong>. Rates and availability are subject to change after this date.</p>
<p style="margin:28px 0 0;font-size:14px;color:#777;text-align:center;font-style:italic;">Warm regards &mdash; we look forward to welcoming you.</p>',
    ],
    'conference_quotation' => [
        'subject' => 'Conference Quotation - {{site_name}} [{{inquiry_reference}}]',
        'html'    => '<h1 style="color:#8B7355;text-align:center;">Conference Quotation</h1>
<p>Dear {{contact_person}},</p>
<p>Your conference quotation is ready.</p>
<p><strong>Inquiry Ref:</strong> {{inquiry_reference}}<br>
<strong>Quotation Ref:</strong> {{quotation_reference}}<br>
<strong>Company:</strong> {{company_name}}<br>
<strong>Room:</strong> {{conference_room}}<br>
<strong>Date:</strong> {{event_date}}<br>
<strong>Time:</strong> {{event_time}}<br>
<strong>Attendees:</strong> {{attendees}}<br>
<strong>Total:</strong> {{total_amount}}<br>
<strong>Valid Until:</strong> {{valid_until}}</p>
<p>{{quotation_notes}}</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{contact_phone}}</p>',
    ],
    'event_quotation' => [
        'subject' => 'Event Quotation - {{site_name}} [{{quotation_reference}}]',
        'html'    => '<h1 style="color:#8B7355;text-align:center;">Event Quotation</h1>
<p>Dear {{recipient_name}},</p>
<p>Your event quotation is ready.</p>
<p><strong>Quotation Ref:</strong> {{quotation_reference}}<br>
<strong>Event:</strong> {{event_title}}<br>
<strong>Date:</strong> {{event_date}}<br>
<strong>Time:</strong> {{event_time}}<br>
<strong>Location:</strong> {{event_location}}<br>
<strong>Attendees:</strong> {{attendee_count}}<br>
<strong>Total:</strong> {{total_amount}}<br>
<strong>Valid Until:</strong> {{valid_until}}</p>
<p>{{quotation_notes}}</p>
<p>Contact: <a href="mailto:{{contact_email}}">{{contact_email}}</a> | {{contact_phone}}</p>',
    ],
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Settings - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="../css/main.css">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-responsive-enhancements.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/admin-booking-settings.css">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-cog" style="color: #8B7355; margin-right: 10px;"></i>
                Booking Settings
            </h1>
        </div>

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

        <div class="settings-card">
            <h2><i class="fas fa-tools" style="color: #8B7355;"></i> Frontend Maintenance Mode</h2>

            <div class="current-value">
                <i class="fas fa-<?php echo $site_maintenance_enabled ? 'triangle-exclamation' : 'check-circle'; ?>" style="color: <?php echo $site_maintenance_enabled ? '#dc3545' : '#28a745'; ?>;"></i>
                <div class="current-value-info">
                    <h3>Frontend is <?php echo $site_maintenance_enabled ? 'In Maintenance' : 'Live'; ?></h3>
                    <div class="value"><?php echo $site_maintenance_enabled ? 'Public pages blocked (admin stays online)' : 'Public pages available'; ?></div>
                </div>
            </div>

            <form method="POST" action="booking-settings.php" id="site-maintenance-toggle-form">
                <input type="hidden" name="toggle_site_maintenance" value="1">
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox"
                            id="site_maintenance_enabled"
                            name="site_maintenance_enabled"
                            value="1"
                            <?php echo $site_maintenance_enabled ? 'checked' : ''; ?>
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
                        rows="4"
                        placeholder="This message appears for all visitors while maintenance mode is active."><?php echo htmlspecialchars($site_maintenance_message); ?></textarea>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        This message is shown on every blocked public page while maintenance mode is enabled.
                    </p>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-save"></i> Save Maintenance Message
                </button>
            </form>
        </div>

        <div class="settings-card">
            <h2><i class="fas fa-toggle-on" style="color: #8B7355;"></i> Booking System Status</h2>

            <div class="current-value">
                <i class="fas fa-<?php echo $booking_enabled ? 'check-circle' : 'times-circle'; ?>" style="color: <?php echo $booking_enabled ? '#28a745' : '#dc3545'; ?>;"></i>
                <div class="current-value-info">
                    <h3>Booking System is <?php echo $booking_enabled ? 'Enabled' : 'Disabled'; ?></h3>
                    <div class="value"><?php echo $booking_enabled ? 'Active' : 'Inactive'; ?></div>
                </div>
            </div>

            <form method="POST" action="booking-settings.php" id="booking-system-toggle-form">
                <input type="hidden" name="toggle_booking_system" value="1">
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox"
                            id="booking_system_enabled"
                            name="booking_system_enabled"
                            value="1"
                            <?php echo $booking_enabled ? 'checked' : ''; ?>
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
                <div style="background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); border-left: 4px solid #ffc107; padding: 20px; border-radius: 8px; margin-top: 25px;">
                    <h4 style="margin: 0 0 10px 0; color: #856404;"><i class="fas fa-exclamation-triangle"></i> Booking System Disabled</h4>
                    <p style="color: #856404; margin-bottom: 15px;">
                        The booking system is currently turned off. Enable it above to allow guests to make online reservations.
                    </p>
                    <div style="display: flex; gap: 15px;">
                        <a href="#disabled-settings" style="color: #ffc107; text-decoration: none; font-weight: 600;">
                            <i class="fas fa-cog"></i> Configure Disabled Message
                        </a>

                    </div>
                </div>

                <div id="disabled-settings" style="display: none; margin-top: 25px;">
                    <h3 style="color: #8B7355; margin-bottom: 20px;"><i class="fas fa-sliders-h"></i> Disabled Mode Settings</h3>

                    <form method="POST" action="booking-settings.php">
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

                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </form>
                </div>

                <hr style="margin: 30px 0; border-top: 2px solid #eee;">
            <?php endif; ?>

            <script>
                // Inject CSRF token into all POST forms on this page (anti-CSRF protection)
                const _bsCsrf = <?php echo json_encode($csrf_token); ?>;
                document.querySelectorAll('form[method="POST"], form[method="post"], form[action="booking-settings.php"]').forEach(function(f) {
                    if (!f.querySelector('[name="csrf_token"]')) {
                        var inp = document.createElement('input');
                        inp.type = 'hidden';
                        inp.name = 'csrf_token';
                        inp.value = _bsCsrf;
                        f.appendChild(inp);
                    }
                });

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

            <div class="settings-card">
                <h2><i class="fas fa-calendar-alt" style="color: #8B7355;"></i> Advance Booking Configuration</h2>

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
                    <summary><i class="fas fa-lightbulb"></i> How this affects your website</summary>
                    <div class="tips-body">
                        <ul>
                            <li>Date pickers only allow dates within this limit</li>
                            <li>Server-side validation rejects bookings beyond this date</li>
                            <li>Guests see a clear message about the booking window</li>
                        </ul>
                    </div>
                </details>
            </div>

            <div class="settings-card" id="tentative">
                <h2><i class="fas fa-clock" style="color: #8B7355;"></i> Tentative Bookings</h2>

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
                    <summary><i class="fas fa-lightbulb"></i> What happens when tentative is disabled</summary>
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

            <div class="settings-card">
                <h2><i class="fas fa-percent" style="color: #8B7355;"></i> Tourism Levy / City Tax</h2>

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
                            value="<?php echo htmlspecialchars(getSetting('tourism_levy_percent', '1.00')); ?>"
                            min="0"
                            max="100"
                            step="0.01"
                            required>
                        <p class="help-text">
                            <i class="fas fa-info-circle"></i>
                            The percentage of the total booking amount (room rate + child supplement) to charge as tourism levy.
                            Default is 1.00%. Common values range from 1% to 5% depending on local regulations.
                        </p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Tourism Levy Settings
                    </button>
                </form>

                <details class="tips-box">
                    <summary><i class="fas fa-lightbulb"></i> How Tourism Levy Works</summary>
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

            <div class="settings-card">
                <h2><i class="fas fa-envelope" style="color: #8B7355;"></i> Email Configuration</h2>

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
                        <p class="help-text"><i class="fas fa-info-circle"></i> Usually your full email address</p>
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

                    <h3 style="color: #1A1A1A; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                        <i class="fas fa-user"></i> Email Identity
                    </h3>

                    <div class="form-group">
                        <label for="email_from_name">From Name *</label>
                        <input type="text" id="email_from_name" name="email_from_name" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_from_name'] ?? ''); ?>" required>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Name that appears as the sender of emails</p>
                    </div>

                    <div class="form-group">
                        <label for="email_from_email">From Email *</label>
                        <input type="email" id="email_from_email" name="email_from_email" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_from_email'] ?? ''); ?>" required>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Email address that appears as the sender</p>
                    </div>

                    <div class="form-group">
                        <label for="email_admin_email">Admin Notification Email</label>
                        <input type="email" id="email_admin_email" name="email_admin_email" class="form-control"
                            value="<?php echo htmlspecialchars($current_settings['email_admin_email'] ?? ''); ?>">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Email address to receive booking notifications (optional)</p>
                    </div>

                    <h3 style="color: #1A1A1A; margin-top: 30px; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 1px solid #e0e0e0;">
                        <i class="fas fa-sliders-h"></i> Email Settings
                    </h3>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_bcc_admin" name="email_bcc_admin" value="1"
                                <?php echo ($current_settings['email_bcc_admin'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>BCC Admin on all emails</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Send a blind carbon copy of all emails to the admin email address</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_development_mode" name="email_development_mode" value="1"
                                <?php echo ($current_settings['email_development_mode'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Development Mode (Preview Only)</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> When checked, emails will be saved as preview files instead of being sent.</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_log_enabled" name="email_log_enabled" value="1"
                                <?php echo ($current_settings['email_log_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Enable Email Logging</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Log all email activity to logs/email-log.txt</p>
                    </div>

                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="email_preview_enabled" name="email_preview_enabled" value="1"
                                <?php echo ($current_settings['email_preview_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>
                            <span>Enable Email Previews</span>
                        </label>
                        <p class="help-text"><i class="fas fa-info-circle"></i> Save HTML previews of emails in logs/email-previews/ folder</p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Email Settings
                    </button>
                </form>

                <details class="tips-box" style="margin-top:10px;">
                    <summary><i class="fas fa-lightbulb"></i> Email Configuration Tips</summary>
                    <div class="tips-body">
                        <ul>
                            <li><strong>Testing:</strong> Use Development Mode to test without actually sending</li>
                            <li><strong>Security:</strong> Passwords are encrypted in the database</li>
                            <li><strong>Logs:</strong> Check <code>logs/email-log.txt</code> for activity history</li>
                            <li><strong>Previews:</strong> Saved to <code>logs/email-previews/</code></li>
                        </ul>
                    </div>
                </details>
            </div>

            <div class="settings-card">
                <h2><i class="fas fa-bell" style="color: #8B7355;"></i> Booking Notification Email</h2>
                <form method="POST" action="booking-settings.php">
                    <input type="hidden" name="booking_notification_settings" value="1">

                    <div class="form-group">
                        <label for="booking_notification_email">Notification Recipient Email</label>
                        <input type="email" id="booking_notification_email" name="booking_notification_email"
                            class="form-control" value="<?php echo htmlspecialchars($current_booking_notification_email ?? ''); ?>"
                            placeholder="reservations@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> New booking notifications are sent to this email first, with fallback to Admin Notification Email.</p>
                    </div>

                    <div class="form-group">
                        <label for="booking_notification_cc_emails">Booking Notification CC Emails</label>
                        <input type="text" id="booking_notification_cc_emails" name="booking_notification_cc_emails"
                            class="form-control" value="<?php echo htmlspecialchars($current_booking_notification_cc_emails ?? ''); ?>"
                            placeholder="accounts@example.com, manager@example.com">
                        <p class="help-text"><i class="fas fa-info-circle"></i> Optional comma-separated CC recipients for all new booking admin notifications.</p>
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Notification Email
                    </button>
                </form>
            </div>

            <div class="settings-card">
                <h2><i class="fas fa-sliders-h" style="color: #8B7355;"></i> Service Modules &amp; Dedicated Notification Emails</h2>
                <form method="POST" action="booking-settings.php">
                    <input type="hidden" name="service_channel_settings" value="1">

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="conference_system_enabled" value="1" <?php echo $current_conference_system_enabled ? 'checked' : ''; ?>>
                            <span>Enable Conference page &amp; conference enquiry functionality</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="conference_email">Conference Notification Email</label>
                        <input type="email" id="conference_email" name="conference_email" class="form-control" value="<?php echo htmlspecialchars($current_conference_email ?? ''); ?>" placeholder="conference@example.com">
                    </div>

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="gym_system_enabled" value="1" <?php echo $current_gym_system_enabled ? 'checked' : ''; ?>>
                            <span>Enable Gym page &amp; gym booking/enquiry functionality</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="gym_email">Gym Notification Email</label>
                        <input type="email" id="gym_email" name="gym_email" class="form-control" value="<?php echo htmlspecialchars($current_gym_email ?? ''); ?>" placeholder="gym@example.com">
                    </div>

                    <div class="form-group">
                        <label style="display:flex; align-items:center; gap:10px;">
                            <input type="checkbox" name="restaurant_system_enabled" value="1" <?php echo $current_restaurant_system_enabled ? 'checked' : ''; ?>>
                            <span>Enable Restaurant page and related restaurant functionality</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="email_restaurant">Restaurant Notification Email</label>
                        <input type="email" id="email_restaurant" name="email_restaurant" class="form-control" value="<?php echo htmlspecialchars($current_restaurant_email ?? ''); ?>" placeholder="restaurant@example.com">
                    </div>

                    <button type="submit" class="btn-submit">
                        <i class="fas fa-save"></i> Save Service Module Settings
                    </button>
                </form>
            </div>

            <div class="settings-card" id="pwa-settings">
                <h2><i class="fas fa-mobile-screen-button" style="color: #8B7355;"></i> PWA Install Banner</h2>
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
                '{{balance_due}}',
                '{{inquiry_reference}}',
                '{{company_name}}',
                '{{contact_person}}',
                '{{conference_room}}',
                '{{event_date}}',
                '{{event_time}}',
                '{{attendees}}',
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
                '{{hotel_phone}}',
                '{{hotel_address}}',
            ];
            ?>
            <div class="settings-card tpl-editor-card">
                <div style="margin-bottom:14px;">
                    <h2 style="margin:0 0 4px;"><i class="fas fa-envelope-open-text" style="color:#8B7355;"></i> Booking Email Templates</h2>
                    <p class="help-text" style="margin:0;">Edit each template's subject and body. Preview updates live while you type with full wrapped email rendering and placeholder replacement.</p>
                </div>

                <!-- Tab bar -->
                <div class="tpl-tabs" role="tablist">
                    <?php foreach ($booking_template_defs as $tkey => $tname):
                        $tBadge = $booking_templates[$tkey];
                        $tIsActive  = (int)($tBadge['is_active'] ?? 1) === 1;
                        $tHasContent = !empty($tBadge['html_body']);
                        $shortNames = [
                            'booking_received' => 'Received',
                            'booking_confirmed' => 'Confirmed',
                            'booking_cancelled' => 'Cancelled',
                            'payment_invoice' => 'Invoice Email',
                            'payment_invoice_document' => 'Invoice PDF',
                            'tentative_booking_created' => 'Tentative New',
                            'tentative_booking_reminder' => 'Tentative Reminder',
                            'tentative_booking_expired' => 'Tentative Expired',
                            'tentative_booking_converted' => 'Tentative Confirmed',
                            'tentative_quotation' => 'Room Quote',
                            'conference_quotation' => 'Conference Quote',
                            'event_quotation' => 'Event Quote',
                            'credit_note' => 'Credit Note',
                        ];
                    ?>
                        <button class="tpl-tab <?php echo $tkey === $activeTabKey ? 'active' : ''; ?>"
                            type="button" data-tpl="<?php echo $tkey; ?>" role="tab"
                            aria-selected="<?php echo $tkey === $activeTabKey ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars($shortNames[$tkey] ?? $tname); ?>
                            <span class="tpl-dot <?php echo $tIsActive ? 'dot-active' : 'dot-inactive'; ?>"
                                title="<?php echo $tIsActive ? 'Active' : 'Inactive'; ?>"></span>
                            <?php if (!$tHasContent): ?><span class="tpl-dot dot-warn" title="Not configured"></span><?php endif; ?>
                        </button>
                    <?php endforeach; ?>
                </div>

                <form method="POST" id="tpl-main-form" action="booking-settings.php">
                    <input type="hidden" name="booking_email_templates" value="1">

                    <?php foreach ($booking_template_defs as $tkey => $tname):
                        $tpl      = $booking_templates[$tkey];
                        $isActive = (int)($tpl['is_active'] ?? 1) === 1;
                        $isShown  = ($tkey === $activeTabKey);
                    ?>
                        <div class="tpl-panel" id="panel-<?php echo $tkey; ?>" <?php echo $isShown ? '' : 'hidden'; ?> role="tabpanel">
                            <div class="tpl-split">

                                <!-- ====== EDITOR ====== -->
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
                                                value="<?php echo htmlspecialchars((string)getSetting('email_admin_email', '')); ?>">
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

                    <div style="padding-top:14px;border-top:1px solid #e8e3d7;margin-top:14px;">
                        <button type="submit" class="btn-submit">
                            <i class="fas fa-save"></i> Save All Templates
                        </button>
                    </div>
                </form>
            </div><!-- /tpl-editor-card -->

            <div class="info-box" style="background:#fff8ed;border-left:3px solid #f59e0b;">
                <p style="color:#92400e;margin:0;font-size:12.5px;"><i class="fas fa-lock"></i> <strong>SMTP passwords are encrypted in the database.</strong> No credentials are stored in config files.</p>
            </div>

            <script>
                (function() {
                    // Seeded defaults for "Load Default" button
                    var tplDefaults = <?php echo json_encode($tplDefaults, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
                    var placeholderTokens = <?php echo json_encode($allPlaceholders, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

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
                                sendArea._previewHtml = data.full_html;
                                sendArea._previewSubject = data.subject;
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

                        fetch('booking-settings.php', {
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
                            var email = emailIn ? emailIn.value.trim() : '';

                            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                                if (feedback) {
                                    feedback.textContent = 'Enter a valid email address.';
                                    feedback.className = 'tpl-send-feedback tpl-feedback-err';
                                    feedback.style.display = 'block';
                                }
                                return;
                            }
                            var html = sendArea ? sendArea._previewHtml : null;
                            var subject = sendArea ? sendArea._previewSubject : 'Test Email';
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
                            fd.set('csrf_token', _bsCsrf);
                            fd.set('test_email_address', email);
                            fd.set('test_subject', subject);
                            fd.set('test_html', html);

                            btn.disabled = true;
                            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

                            fetch('booking-settings.php', {
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

            <?php require_once 'includes/admin-footer.php'; ?>
