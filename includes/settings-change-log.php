<?php
/**
 * Hotel Settings change log (owner 2026-11-14: "logs for any changes in hotel settings").
 *
 * One admin_activity_log row (action 'setting_change') per setting that really changed on Hotel Settings
 * (admin/booking-settings.php): who, when, which setting (plain label), old -> new. Shown on System Logs
 * (#log-settings) and in Staff activity ("Hotel Settings changes").
 *
 * Opt-in context: nothing is ever recorded unless a request switched the context on. admin/booking-settings.php
 * calls rh_settings_log_begin() around its save handlers and rh_settings_log_end() before anything else runs
 * (render, AJAX exits); rh_scheduler_execute() also ends it first thing, so the scheduler, backups, the mirror script
 * and every other page never write these rows. begin() always starts fresh (never inherits an older context).
 *
 * Hooks: updateSetting() and updateEmailSetting() (config/database.php) read the OLD value straight from the table
 * (rh_settings_log_old) before the write and call rh_settings_log_record() after a successful write. Only real
 * changes are recorded (old !== new, compared as trimmed strings). Secrets (passwords, API keys, tokens, SMTP
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
     */
    function rh_settings_log_begin(array $who = [], ?callable $sink = null): void
    {
        $session = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : [];
        $uid = (int)($who['id'] ?? ($session['admin_user_id'] ?? 0));
        $username = trim((string)($who['username'] ?? ($session['admin_username'] ?? '')));
        $GLOBALS['rh_settings_log_ctx'] = [
            'uid' => $uid > 0 ? $uid : 0,
            'username' => function_exists('mb_substr') ? mb_substr($username, 0, 100) : substr($username, 0, 100),
            'ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'sink' => $sink,
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

    /** A value saved by review_source_encrypt() ('rk1:' + base64). */
    function rh_settings_log_secret_value($v): bool
    {
        return is_string($v) && strpos($v, 'rk1:') === 0;
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
     * Read a details text back: [key, label, old, new, hidden] or null. Values may contain ';' and '=': the order is
     * fixed, old ends at the first "; new=" (never inside old, see rh_settings_log_details) and new runs to the end.
     */
    function rh_settings_log_parse(string $details): ?array
    {
        if (!preg_match('/^key=([A-Za-z0-9_.-]{1,100}); label=(.*?); old=(.*?); new=(.*)$/s', $details, $m)) {
            return null;
        }
        $hidden = $m[3] === '(hidden)' && $m[4] === '(hidden)';
        return ['key' => $m[1], 'label' => $m[2] !== '' ? $m[2] : rh_settings_log_label($m[1]), 'old' => $m[3], 'new' => $m[4], 'hidden' => $hidden];
    }

    /** The sentence for Staff activity: 'Changed Tourism levy % from 1 to 1.5' / 'Changed Email server password (value hidden)'. */
    function rh_settings_log_sentence(string $details): string
    {
        $p = rh_settings_log_parse($details);
        if ($p === null) {
            return 'Changed a Hotel Settings value';
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
