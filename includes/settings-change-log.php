<?php
/**
 * Settings change log (owner 2026-11-14: "logs for any changes in hotel settings", extended the same day to the other
 * settings screens listed in rh_settings_log_pages()).
 *
 * One admin_activity_log row (action 'setting_change') per setting that really changed on a settings screen: which
 * page, who, when, which setting (plain label), old -> new. Shown on System Logs (#log-settings, "Settings changes")
 * and in Staff activity ("Settings changes"). Details: "page=<file>; key=..; label=..; old=..; new=.." (rows written
 * before the page was stored start at "key=" and came from Hotel Settings).
 *
 * Opt-in context: nothing is ever recorded unless a request switched the context on. Each settings page
 * calls rh_settings_log_begin() around its save handlers and rh_settings_log_end() before anything else runs
 * (render, AJAX exits); rh_scheduler_execute() also ends it first thing, so the scheduler, backups, the mirror script
 * and every other page never write these rows. begin() always starts fresh (never inherits an older context).
 *
 * Hooks: updateSetting() and updateEmailSetting() (config/database.php) read the OLD value straight from the table
 * (rh_settings_log_old) before the write and call rh_settings_log_record() after a successful write. Only real
 * changes are recorded (old !== new, compared as trimmed strings; two decimal numbers that are equal, "16.50" and
 * "16.5", are no change). Secrets (passwords, API keys, tokens, SMTP
 * credentials, encrypted values) are stored as "(hidden)"; bank account numbers keep their last 4 digits only.
 *
 * Loading this file connects to nothing. Never throws, never blocks a save, one INSERT per change.
 * Pure helpers are tested by scripts/tests/settings_change_log.php.
 */

if (!function_exists('rh_settings_log_begin')) {

    /* ── context ─────────────────────────────────────────────────────── */

    /**
     * Switch the context on for this request (always fresh: any older context is dropped).
     * $who: id, username (else the session's). $sink: tests only, receives the row instead of the database.
     * $page: the settings screen the change was made on (e.g. 'module-settings.php' for its JSON endpoints); default
     * the running script's file name. Stored in front of each row's details as "page=<file>; ".
     */
    function rh_settings_log_begin(array $who = [], ?callable $sink = null, string $page = ''): void
    {
        $session = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
        $uid = (int)($who['id'] ?? ($session['admin_user_id'] ?? 0));
        $username = trim((string)($who['username'] ?? ($session['admin_username'] ?? '')));
        if ($page === '') {
            $page = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
        }
        $page = strtolower($page);
        $GLOBALS['rh_settings_log_ctx'] = [
            'uid' => $uid > 0 ? $uid : 0,
            'username' => function_exists('mb_substr') ? mb_substr($username, 0, 100) : substr($username, 0, 100),
            'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'sink' => $sink,
            'page' => preg_match('/^[a-z0-9_-]{1,80}\.php$/', $page) ? $page : '',
        ];
    }

    /** Switch the context off. Safe to call any number of times. */
    function rh_settings_log_end(): void
    {
        unset($GLOBALS['rh_settings_log_ctx']);
    }

    function rh_settings_log_on(): bool
    {
        return isset($GLOBALS['rh_settings_log_ctx']) && is_array($GLOBALS['rh_settings_log_ctx']);
    }

    /* ── pure helpers ────────────────────────────────────────────────── */

    /** Keys written as a side effect (counters, timestamps, the old-name mirror), never by a person choosing them. */
    function rh_settings_log_ignored(string $key): bool
    {
        if (preg_match('/^(reviews_google_calls_|scheduler_|last_backup_)/', $key)) {
            return true;
        }
        $mirrors = ['child_guest_price_multiplier' => true];
        if (function_exists('rh_hotel_details_fields')) {
            foreach (rh_hotel_details_fields() as $fields) {
                foreach ($fields as $spec) {
                    if (!empty($spec['mirror'])) {
                        $mirrors[(string)$spec['mirror']] = true;
                    }
                }
            }
        }
        return isset($mirrors[$key]);
    }

    /** Is this key a secret by its name? (passwords, API keys, tokens, SMTP credentials, encrypted values) */
    function rh_settings_log_secret_key(string $key): bool
    {
        return (bool)preg_match('/password|passwd|(^|_)pass(_|$)|secret|token|api_?key|(^|_)key$|smtp_user|encrypt/i', $key);
    }

    /** An encrypted value: review_source_encrypt() 'rk1:', encryptWhatsAppToken() 'wa1:', facebookEncryptSecret() 'fb1:'. */
    function rh_settings_log_secret_value($v): bool
    {
        return is_string($v) && preg_match('/^(rk1|wa1|fb1):/', $v) === 1;
    }

    /** Two plain numbers with a decimal point that are the same number ("16.50" and "16.5"): not a change. */
    function rh_settings_log_same_number(string $a, string $b): bool
    {
        $num = '/^-?\d{1,15}(\.\d{1,10})?$/';
        return (strpos($a, '.') !== false || strpos($b, '.') !== false)
            && preg_match($num, $a) === 1 && preg_match($num, $b) === 1 && (float)$a === (float)$b;
    }

    /** The settings screens that write these rows: file => plain page name (System Logs "Page" column and filter). */
    function rh_settings_log_pages(): array
    {
        return [
            'booking-settings.php' => 'Hotel Settings',
            'email-templates.php' => 'Email Templates', // runs booking-settings.php in templates-only mode
            'automated-emails.php' => 'Automated Emails',
            'accounting-dashboard.php' => 'Accounting (VAT)',
            'module-settings.php' => 'Modules',
            'station-settings.php' => 'Station Hours',
            'whatsapp-settings.php' => 'WhatsApp Settings',
            'facebook-settings.php' => 'Facebook Settings',
            'gym-schedule.php' => 'Gym Schedule',
            'gym-members.php' => 'Gym Members',
            'footer-management.php' => 'Website Footer',
        ];
    }

    /** Plain page name for a stored file name: the list above, else "Some page" from the file name. */
    function rh_settings_log_page_name(string $file): string
    {
        $pages = rh_settings_log_pages();
        if (isset($pages[$file])) {
            return $pages[$file];
        }
        $t = trim(str_replace(['-', '_'], ' ', preg_replace('/\.php$/', '', $file)));
        return $t !== '' ? ucfirst($t) : 'A settings page';
    }

    /** One value as it is stored in the log: one line, trimmed, at most $max characters ("..." when cut). */
    function rh_settings_log_clip($v, int $max = 120): string
    {
        $s = is_bool($v) ? ($v ? '1' : '0') : (is_scalar($v) ? (string)$v : '');
        $s = trim((string)preg_replace('/\s+/u', ' ', $s));
        $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        if ($len > $max) {
            $s = rtrim(function_exists('mb_substr') ? mb_substr($s, 0, $max - 3) : substr($s, 0, $max - 3)) . '...';
        }
        return $s;
    }

    /** "123456789" -> "*****6789" (the same mask the Hotel details event log uses). */
    function rh_settings_log_mask_account(string $v): string
    {
        return strlen($v) > 4 ? str_repeat('*', strlen($v) - 4) . substr($v, -4) : $v;
    }

    /** Plain names for the settings Hotel Settings writes. The Hotel details registry adds its own labels. */
    function rh_settings_log_label_map(): array
    {
        return [
            'booking_system_enabled' => 'Online bookings switched on',
            'tentative_bookings_enabled' => 'Tentative bookings allowed',
            'cancellation_refund_mode' => 'Cancellation handling',
            'guest_self_cancel_refund' => 'Refund when a guest cancels online',
            'nonroom_cancel_mode' => 'Cancelled conference / gym / event money',
            'refund_method_default' => 'Refund method',
            'group_noshow_charge' => 'No-show charge for group rooms',
            'booking_reference_prefix' => 'Booking reference prefix',
            'brand_primary_color' => 'Document primary colour',
            'brand_accent_color' => 'Document accent colour',
            'document_footer_text' => 'Document footer line',
            'document_terms_text' => 'Document terms',
            'document_logo_height_mm' => 'Document logo height (mm)',
            'site_maintenance_enabled' => 'Website maintenance mode',
            'site_maintenance_message' => 'Website maintenance message',
            'booking_disabled_action' => 'When online bookings are off',
            'booking_disabled_message' => 'Message when online bookings are off',
            'booking_disabled_redirect_url' => 'Link when online bookings are off',
            'max_advance_booking_days' => 'Maximum days ahead a booking can be made',
            'tourism_levy_enabled' => 'Tourism levy switched on',
            'tourism_levy_percent' => 'Tourism levy %',
            'booking_notification_email' => 'New website booking alerts go to',
            'booking_notification_cc_emails' => 'Booking alert copies (CC)',
            'booking_prearrival_reminder_enabled' => 'Before-arrival reminder email',
            'booking_prearrival_reminder_days' => 'Before-arrival reminder (days before)',
            'booking_poststay_review_enabled' => 'After-stay review request email',
            'booking_poststay_review_days' => 'After-stay review request (days after)',
            'conference_system_enabled' => 'Conference bookings switched on',
            'gym_system_enabled' => 'Gym bookings switched on',
            'restaurant_system_enabled' => 'Restaurant switched on',
            'conference_email' => 'Conference notification email',
            'gym_email' => 'Gym notification email',
            'email_restaurant' => 'Restaurant notification email',
            'pwa_install_dismiss_days' => 'App install banner: ask again after (days)',
            'receipt_whatsapp_template' => 'Receipt WhatsApp message',
            'smtp_host' => 'Email server (SMTP host)',
            'smtp_port' => 'Email server port',
            'smtp_username' => 'Email server username',
            'smtp_password' => 'Email server password',
            'smtp_secure' => 'Email server security',
            'email_from_name' => 'Sender name',
            'email_from_email' => 'Reply-to and contact email',
            'email_admin_email' => 'Staff copy email',
            'email_bcc_admin' => 'Hidden staff copy of guest emails',
            'email_development_mode' => 'Email development mode',
            'email_log_enabled' => 'Email logging',
            'email_preview_enabled' => 'Email preview only (not sent)',
            'invoice_recipients' => 'Invoice copies go to',
            'email_reply_to_backup' => 'Backup reply-to emails',
            'email_admin_backup' => 'Backup staff copy emails',
            'reviews_google_places_key' => 'Google Places API key',
            'reviews_brave_search_key' => 'Brave Search API key',
            'reviews_google_place_id' => 'Google Place ID',
            // Automated Emails (admin/automated-emails.php)
            'automated_email_master' => 'Automated emails master switch',
            'automated_email_test_recipient' => 'Test mode: send every automated email to',
            'automated_email_reminder_stages' => 'Overdue reminder days',
            'automated_email_payment_terms_days' => 'Payment terms (days)',
            'automated_email_lookback_days' => 'Look back for overdue bookings (days)',
            'automated_email_quotation_days' => 'Quotation expiry reminder (days before)',
            'automated_email_interval_minutes' => 'Check for emails to send every (minutes)',
            'automated_email_bcc_hotel' => 'Hidden hotel copy of automated emails',
            'automated_job_daily_reports_cc' => 'Daily reports email copies (CC)',
            'automated_job_station_reports_to' => 'Station report emails go to',
            'automated_job_station_reports_cc' => 'Station report emails copies (CC)',
            'automated_job_company_statements_cc' => 'Company statements copies (CC)',
            'automated_email_job_overdue' => 'Job: overdue payment reminders',
            'automated_email_job_quotation' => 'Job: quotation expiry reminders',
            'automated_email_job_tentative' => 'Job: tentative hold reminders',
            'automated_email_job_tentative_expired' => 'Job: tentative hold expired notices',
            'automated_email_job_pending_expired' => 'Job: pending booking expired notices',
            'automated_email_job_unpaid_reminder' => 'Job: confirmed-but-unpaid payment reminders',
            'automated_email_job_unpaid_release' => 'Job: confirmed-but-unpaid room release',
            'automated_job_recurring_maintenance' => 'Job: recurring maintenance',
            'automated_job_stock_expiry' => 'Job: expired stock write-off',
            'automated_job_daily_reports' => 'Job: daily station reports email',
            'automated_job_station_reports' => 'Job: station report emails',
            'automated_job_company_statements' => 'Job: company statements',
            'automated_backup_enabled' => 'Job: nightly database backup',
            // VAT (admin/accounting-dashboard.php)
            'vat_enabled' => 'VAT switched on',
            'vat_rate' => 'VAT rate %',
            'vat_number' => 'VAT number',
            'vat_pricing_mode' => 'VAT pricing (inclusive = prices include VAT)',
            // Modules (admin/module-settings.php)
            'data_import_enabled' => 'Data Import switched on',
            'events_system_enabled' => 'Events page on the website',
            'business_preset' => 'Business type',
            // WhatsApp Settings
            'whatsapp_enabled' => 'WhatsApp switched on',
            'whatsapp_api_token' => 'WhatsApp access token',
            'whatsapp_phone_id' => 'WhatsApp phone number ID',
            'whatsapp_business_id' => 'WhatsApp business account ID',
            'whatsapp_number' => 'Hotel WhatsApp number',
            'whatsapp_notify_on_booking' => 'WhatsApp on new booking',
            'whatsapp_notify_on_confirmation' => 'WhatsApp on confirmation',
            'whatsapp_notify_on_cancellation' => 'WhatsApp on cancellation',
            'whatsapp_notify_on_checkin' => 'WhatsApp on check-in',
            'whatsapp_notify_on_checkout' => 'WhatsApp on check-out',
            'whatsapp_guest_notifications' => 'WhatsApp messages to guests',
            'whatsapp_hotel_notifications' => 'WhatsApp messages to the hotel',
            'whatsapp_admin_numbers' => 'Admin WhatsApp numbers',
            'whatsapp_confirmed_template' => 'WhatsApp confirmed template',
            'whatsapp_cancelled_template' => 'WhatsApp cancelled template',
            // Facebook Settings
            'facebook_posting_enabled' => 'Facebook posting switched on',
            'facebook_page_id' => 'Facebook Page ID',
            'facebook_page_name' => 'Facebook Page name',
            'facebook_default_hashtags' => 'Facebook default hashtags',
            'facebook_rooms_enabled' => 'Facebook posts: rooms',
            'facebook_events_enabled' => 'Facebook posts: events',
            'facebook_conference_enabled' => 'Facebook posts: conference',
            'facebook_gym_enabled' => 'Facebook posts: gym',
            'facebook_menu_enabled' => 'Facebook posts: menu',
            'facebook_post_log_enabled' => 'Facebook post log',
            'facebook_app_id' => 'Facebook App ID',
            'facebook_login_config_id' => 'Facebook login configuration ID',
            'facebook_app_secret' => 'Facebook App Secret',
            'facebook_page_access_token' => 'Facebook Page access token',
            // Gym (admin/gym-schedule.php, admin/gym-members.php)
            'gym_slot_booking_enabled' => 'Gym slot booking switched on',
            'gym_slot_capacity' => 'Gym people per slot',
            'gym_slot_duration_minutes' => 'Gym slot length (minutes)',
            'gym_slot_advance_days' => 'Gym slots bookable ahead (days)',
            'gym_reminder_enabled' => 'Gym renewal reminders',
            'gym_reminder_days' => 'Gym renewal reminder (days before expiry)',
            // Website footer (admin/footer-management.php)
            'site_name' => 'Site name',
            'site_tagline' => 'Tagline',
            'site_logo' => 'Logo link',
            'phone_main' => 'Main phone number',
            'email_main' => 'Main email address',
            'address_line1' => 'Address',
            'working_hours' => 'Working hours',
            'facebook_url' => 'Facebook link',
            'instagram_url' => 'Instagram link',
            'twitter_url' => 'Twitter / X link',
            'linkedin_url' => 'LinkedIn link',
            'footer_credits' => 'Footer credits line',
            'footer_design_credit' => 'Footer design credit',
        ];
    }

    /** The plain label: the map, else the Hotel details registry, else "Some setting key" from the key. */
    function rh_settings_log_label(string $key): string
    {
        $map = rh_settings_log_label_map();
        if (isset($map[$key])) {
            return $map[$key];
        }
        if (function_exists('rh_hotel_details_fields')) {
            foreach (rh_hotel_details_fields() as $fields) {
                if (isset($fields[$key]['label'])) {
                    return (string)$fields[$key]['label'];
                }
            }
        }
        // Station Hours: station_<station>_opens_at / _closes_at (includes/station-hours.php).
        if (preg_match('/^station_([a-z0-9_]+)_(opens|closes)_at$/', $key, $sm)) {
            $defs = function_exists('rh_station_definitions') ? rh_station_definitions() : [];
            $name = isset($defs[$sm[1]]['label']) ? (string)$defs[$sm[1]]['label'] : ucfirst(str_replace('_', ' ', $sm[1]));
            return $name . ($sm[2] === 'opens' ? ' opens at' : ' closes at');
        }
        $t = trim(str_replace('_', ' ', $key));
        return $t !== '' ? ucfirst($t) : 'Setting';
    }

    /**
     * The details text for one change, or null when nothing should be recorded (ignored key, no change).
     * "key=tourism_levy_percent; label=Tourism levy %; old=1; new=1.5"; a secret: "old=(hidden); new=(hidden)".
     * $encrypted: either side is stored encrypted.
     */
    function rh_settings_log_details(string $key, $old, $new, bool $encrypted = false): ?string
    {
        $key = trim($key);
        if ($key === '' || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $key) || rh_settings_log_ignored($key)) {
            return null;
        }
        $o = is_bool($old) ? ($old ? '1' : '0') : (is_scalar($old) ? trim((string)$old) : '');
        $n = is_bool($new) ? ($new ? '1' : '0') : (is_scalar($new) ? trim((string)$new) : '');
        $secret = $encrypted || rh_settings_log_secret_key($key) || rh_settings_log_secret_value($o) || rh_settings_log_secret_value($n);
        // Same value = no change. Only an encrypted (is_encrypted or "rk1:") value cannot be compared, so it reads
        // "changed" when saved again; a plain stored secret (e.g. the SMTP username) is compared like any other value.
        if ($o === $n && ($o === '' || (!$encrypted && !rh_settings_log_secret_value($o)))) {
            return null;
        }
        if (!$secret && rh_settings_log_same_number($o, $n)) {
            return null; // "16.50" saved as "16.5" is the same rate, not a change
        }
        $label = rh_settings_log_clip(str_replace(';', ',', rh_settings_log_label($key)), 120);
        if ($secret) {
            $ov = $nv = '(hidden)';
        } elseif ($key === 'bank_account_number') {
            $ov = rh_settings_log_mask_account(rh_settings_log_clip($o));
            $nv = rh_settings_log_mask_account(rh_settings_log_clip($n));
        } else {
            $ov = str_replace('; new=', ', new=', rh_settings_log_clip($o)); // so the read-back split is exact
            $nv = rh_settings_log_clip($n);
        }
        return 'key=' . $key . '; label=' . $label . '; old=' . $ov . '; new=' . $nv;
    }

    /**
     * Read a details text back: [page, key, label, old, new, hidden] or null. Values may contain ';' and '=': the order
     * is fixed, old ends at the first "; new=" (never inside old, see rh_settings_log_details) and new runs to the end.
     * "page=<file>; " in front is optional: rows written before 2026-11-14 have none and came from Hotel Settings.
     */
    function rh_settings_log_parse(string $details): ?array
    {
        if (!preg_match('/^(?:page=([a-z0-9_-]{1,80}\.php); )?key=([A-Za-z0-9_.-]{1,100}); label=(.*?); old=(.*?); new=(.*)$/s', $details, $m)) {
            return null;
        }
        $hidden = $m[4] === '(hidden)' && $m[5] === '(hidden)';
        return ['page' => $m[1] !== '' ? $m[1] : 'booking-settings.php', 'key' => $m[2], 'label' => $m[3] !== '' ? $m[3] : rh_settings_log_label($m[2]),
            'old' => $m[4], 'new' => $m[5], 'hidden' => $hidden];
    }

    /** The sentence for Staff activity: 'Changed Tourism levy % from 1 to 1.5' / 'Changed Email server password (value hidden)'. */
    function rh_settings_log_sentence(string $details): string
    {
        $p = rh_settings_log_parse($details);
        if ($p === null) {
            return 'Changed a setting';
        }
        if ($p['hidden']) {
            return 'Changed ' . $p['label'] . ' (value hidden)';
        }
        $w = static function (string $v): string {
            return $v === '' ? '(blank)' : $v;
        };
        return 'Changed ' . $p['label'] . ' from ' . $w($p['old']) . ' to ' . $w($p['new']);
    }

    /* ── database (app $pdo only, never throws) ──────────────────────── */

    /**
     * The value as stored right now, read from the table (not the settings cache), only while the context is on:
     * ['value' => string ('' when there is no row), 'encrypted' => bool], or null (context off, ignored key, error).
     * $store: 'site_settings' or 'email_settings'.
     */
    function rh_settings_log_old($pdo, string $store, string $key): ?array
    {
        if (!rh_settings_log_on() || rh_settings_log_ignored($key)) {
            return null;
        }
        try {
            if (!($pdo instanceof PDO)) {
                return null;
            }
            if ($store === 'email_settings') {
                $st = $pdo->prepare('SELECT setting_value, is_encrypted FROM email_settings WHERE setting_key = ? LIMIT 1');
                $st->execute([$key]);
                $r = $st->fetch(PDO::FETCH_ASSOC);
                return ['value' => $r ? (string)$r['setting_value'] : '', 'encrypted' => $r ? (bool)(int)$r['is_encrypted'] : false];
            }
            if ($store === 'site_settings') {
                $st = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
                $st->execute([$key]);
                $v = $st->fetchColumn();
                return ['value' => $v === false || $v === null ? '' : (string)$v, 'encrypted' => false];
            }
        } catch (Throwable $e) {
            error_log('settings change log read: ' . $e->getMessage());
        }
        return null;
    }

    /**
     * Record one change (after a successful write). Returns what happened: 'off' (no context), 'same' (no change or
     * ignored key), 'written', or 'failed'. Never throws.
     */
    function rh_settings_log_record(string $store, string $key, $old, $new, bool $encrypted = false): string
    {
        try {
            if (!rh_settings_log_on()) {
                return 'off';
            }
            $details = rh_settings_log_details($key, $old, $new, $encrypted);
            if ($details === null) {
                return 'same';
            }
            $ctx = $GLOBALS['rh_settings_log_ctx'];
            if (($ctx['page'] ?? '') !== '') {
                $details = 'page=' . $ctx['page'] . '; ' . $details; // which settings screen (System Logs "Page")
            }
            $row = [(int)$ctx['uid'], $ctx['username'], 'setting_change', $details, $ctx['ip'], date('Y-m-d H:i:s')];
            if (is_callable($ctx['sink'] ?? null)) {
                ($ctx['sink'])($row, $store);
                return 'written';
            }
            $pdo = $GLOBALS['pdo'] ?? null;
            if (!($pdo instanceof PDO)) {
                return 'failed';
            }
            $st = $pdo->prepare('INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, ?, ?)');
            $st->execute($row);
            return 'written';
        } catch (Throwable $e) {
            error_log('settings change log write: ' . $e->getMessage());
            return 'failed';
        }
    }
}
