<?php

/**
 * Admin -> Hotel Settings -> "Hotel details & policies".
 *
 * Settings that the system reads but that had no editor anywhere in admin. One registry
 * drives the form, the validation and the save, so the default shown here is the same
 * default every consumer uses. Payment/document fields need finance_settings on top of
 * the page's booking_settings permission.
 *
 * Deliberately NOT here: restaurant_tax_pct (F&B prices are gross - owner rule),
 * invoice_start_number (live finance sequence), legacy alias keys (hotel_email, site_phone,
 * logo_url ...), and switches owned by other pages (modules, WhatsApp, Facebook).
 */

if (!function_exists('rh_hotel_details_fields')) {

    /** @return array<string,array<string,array>> group => key => spec */
    function rh_hotel_details_fields(): array
    {
        return [
            'Hotel identity & contact' => [
                // Used where no browser request says which domain we are on: links and logos in
                // emails and PDFs, password-reset links, the {{site_url}} template tag. Website
                // pages themselves detect their own domain and ignore this.
                'site_url'                 => ['label' => 'Website address (used in emails, documents and password-reset links)', 'type' => 'siteurl', 'default' => ''],
                'site_short_name'          => ['label' => 'Short name (app icon / home screen)', 'type' => 'text', 'max' => 30, 'default' => ''],
                'hotel_star_rating'        => ['label' => 'Star rating (0 = not rated)', 'type' => 'int', 'min' => 0, 'max' => 5, 'default' => '5'],
                'price_range_indicator'    => ['label' => 'Price range (search engines)', 'type' => 'select', 'options' => ['$', '$$', '$$$', '$$$$'], 'default' => '$$$'],
                'hotel_address'            => ['label' => 'Full postal address (documents)', 'type' => 'textarea', 'max' => 255, 'default' => ''],
                'address_region'           => ['label' => 'Region / district (contact page)', 'type' => 'text', 'max' => 100, 'default' => ''],
                'phone_secondary'          => ['label' => 'Second phone number', 'type' => 'phone', 'max' => 30, 'default' => ''],
                'admin_notification_email' => ['label' => 'Staff notification email', 'type' => 'email', 'default' => ''],
                'google_maps_embed'        => ['label' => 'Google Maps embed (link or iframe code from Google Maps > Share > Embed)', 'type' => 'maps', 'default' => ''],
            ],
            'Stay policies' => [
                'check_in_time'                  => ['label' => 'Check-in from', 'type' => 'time12', 'default' => '2:00 PM'],
                'check_out_time'                 => ['label' => 'Check-out by', 'type' => 'time12', 'default' => '11:00 AM'],
                'tentative_reminder_hours'       => ['label' => 'Tentative hold reminder (hours before it lapses)', 'type' => 'int', 'min' => 1, 'max' => 168, 'default' => '24'],
                'pending_expiry_hours'           => ['label' => 'Unpaid pending bookings lapse after (hours since booked, 0 = never)', 'type' => 'int', 'min' => 0, 'max' => 720, 'default' => '48'],
                'unpaid_confirmed_release_hours' => ['label' => 'Confirmed bookings with no payment are released after (hours since booked, 0 = never)', 'type' => 'int', 'min' => 0, 'max' => 2160, 'default' => '0'],
                'unpaid_confirmed_reminder_hours' => ['label' => 'Remind the guest to pay (hours before that release, 0 = no reminder)', 'type' => 'int', 'min' => 0, 'max' => 720, 'default' => '24'],
                'cancellation_notice_days'       => ['label' => 'Guest self-cancel notice (days before arrival, 0 = any time)', 'type' => 'int', 'min' => 0, 'max' => 60, 'default' => '0'],
                'booking_time_buffer_minutes'    => ['label' => 'Booking time buffer (minutes)', 'type' => 'int', 'min' => 0, 'max' => 720, 'default' => '60'],
                'booking_child_price_multiplier' => ['label' => 'Child price (% of the adult rate)', 'type' => 'int', 'min' => 0, 'max' => 100, 'default' => '50', 'mirror' => 'child_guest_price_multiplier'],
            ],
            'Payments & documents' => [
                'bank_name'                 => ['label' => 'Bank name', 'type' => 'text', 'max' => 100, 'default' => '', 'finance' => true],
                'bank_account_name'         => ['label' => 'Account name', 'type' => 'text', 'max' => 150, 'default' => '', 'finance' => true],
                'bank_account_number'       => ['label' => 'Account number', 'type' => 'account', 'max' => 50, 'default' => '', 'finance' => true],
                'bank_branch'               => ['label' => 'Branch', 'type' => 'text', 'max' => 100, 'default' => '', 'finance' => true],
                'payment_method_note'       => ['label' => 'Payment methods note', 'type' => 'text', 'max' => 255, 'default' => 'We accept cash payments only.', 'finance' => true],
                'payment_info'              => ['label' => 'Payment info in arrival reminders', 'type' => 'textarea', 'max' => 500, 'default' => 'Payment will be collected at the hotel reception upon check-in.', 'finance' => true],
                'invoice_terms'             => ['label' => 'Invoice terms', 'type' => 'textarea', 'max' => 1000, 'default' => '', 'finance' => true],
                'invoice_footer'            => ['label' => 'Invoice footer', 'type' => 'text', 'max' => 255, 'default' => '', 'finance' => true],
                'receipt_footer'            => ['label' => 'Receipt footer', 'type' => 'text', 'max' => 255, 'default' => '', 'finance' => true],
                'quotation_footer_text'     => ['label' => 'Quotation footer', 'type' => 'text', 'max' => 255, 'default' => '', 'finance' => true],
                'credit_note_expiry_months' => ['label' => 'Credit notes expire after (months, 0 = never)', 'type' => 'int', 'min' => 0, 'max' => 120, 'default' => '12', 'finance' => true],
                'eod_report_cc_emails'      => ['label' => 'End-of-day report CC (comma-separated emails)', 'type' => 'emails', 'default' => '', 'finance' => true],
            ],
            'Restaurant & stock' => [
                'restaurant_tagline'            => ['label' => 'Restaurant tagline', 'type' => 'text', 'max' => 100, 'default' => 'Fresh. Local. Inspired.'],
                'restaurant_receipt_footer'     => ['label' => 'Restaurant receipt footer', 'type' => 'text', 'max' => 160, 'default' => 'Thank you for dining with us!'],
                'restaurant_menu_pdf_url'       => ['label' => 'Menu PDF link (optional)', 'type' => 'url', 'default' => ''],
                'restaurant_service_charge_pct' => ['label' => 'Restaurant service charge (%)', 'type' => 'decimal', 'min' => 0, 'max' => 25, 'default' => '0', 'finance' => true],
                'stock_variance_alert_pct'      => ['label' => 'Stock count: alert at variance (%)', 'type' => 'decimal', 'min' => 0, 'max' => 100, 'default' => '2'],
                'stock_variance_block_pct'      => ['label' => 'Stock count: needs approval above (%)', 'type' => 'decimal', 'min' => 0, 'max' => 100, 'default' => '10'],
                'stock_variance_min_cost'       => ['label' => 'Stock count: ignore variances worth less than', 'type' => 'decimal', 'min' => 0, 'max' => 100000000, 'default' => '5000'],
            ],
        ];
    }

    /** Current value as the consumers see it (stored value, else the shared default). */
    function rh_hotel_details_value(string $key, array $spec): string
    {
        $v = getSetting($key, null);
        return ($v === null || $v === false) ? (string)$spec['default'] : (string)$v;
    }

    /**
     * Validate one posted value. @return array{0:?string,1:?string} [normalised value, error]
     */
    function rh_hotel_details_clean(string $key, array $spec, string $raw): array
    {
        $raw = trim(str_replace("\0", '', $raw));
        $label = $spec['label'];
        $max = (int)($spec['max'] ?? 255);
        switch ($spec['type']) {
            case 'text':
            case 'textarea':
                $raw = $spec['type'] === 'text' ? preg_replace('/\s+/u', ' ', $raw) : preg_replace("/\r\n?/", "\n", $raw);
                return mb_strlen($raw) > $max ? [null, "$label: at most $max characters."] : [$raw, null];
            case 'int':
                if ($raw === '' || !preg_match('/^-?\d+$/', $raw) || (int)$raw < $spec['min'] || (int)$raw > $spec['max']) {
                    return [null, "$label: a whole number from {$spec['min']} to {$spec['max']}."];
                }
                return [(string)(int)$raw, null];
            case 'decimal':
                $n = str_replace(',', '', $raw);
                if ($n === '' || !is_numeric($n) || (float)$n < $spec['min'] || (float)$n > $spec['max']) {
                    return [null, "$label: a number from {$spec['min']} to {$spec['max']}."];
                }
                return [rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'), null];
            case 'select':
                return in_array($raw, $spec['options'], true) ? [$raw, null] : [null, "$label: choose one of the options."];
            case 'time12':
                // Strict shape first: DateTime would silently roll 25:99 over to the next day.
                if (!preg_match('/^(([01]?\d|2[0-3]):[0-5]\d|(1[0-2]|0?[1-9]):[0-5]\d ?[AaPp][Mm])$/', $raw)) {
                    return [null, "$label: a time such as 2:00 PM or 14:00."];
                }
                $t = DateTime::createFromFormat('!H:i', $raw) ?: DateTime::createFromFormat('!g:i A', strtoupper($raw)) ?: DateTime::createFromFormat('!g:iA', strtoupper(str_replace(' ', '', $raw)));
                return $t ? [$t->format('g:i A'), null] : [null, "$label: a time such as 2:00 PM or 14:00."];
            case 'phone':
                if ($raw === '') return ['', null];
                return (preg_match('/^\+?[0-9 ()-]{6,}$/', $raw) && strlen($raw) <= $max) ? [$raw, null] : [null, "$label: digits, spaces, ( ) - and a leading + only."];
            case 'account':
                if ($raw === '') return ['', null];
                return (preg_match('/^[A-Za-z0-9 -]{3,}$/', $raw) && strlen($raw) <= $max) ? [$raw, null] : [null, "$label: letters, digits, spaces and dashes only."];
            case 'email':
                if ($raw === '') return ['', null];
                return (filter_var($raw, FILTER_VALIDATE_EMAIL) && strlen($raw) <= 255) ? [strtolower($raw), null] : [null, "$label: not a valid email address."];
            case 'emails':
                $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[,;\s]+/', strtolower($raw))))));
                foreach ($list as $e) {
                    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return [null, "$label: '$e' is not a valid email address."];
                }
                $out = implode(',', $list);
                return strlen($out) > 1000 ? [null, "$label: too many addresses."] : [$out, null];
            case 'siteurl':
                if ($raw === '') return ['', null];
                if (!filter_var($raw, FILTER_VALIDATE_URL) || !preg_match('#^https?://[^/?\#]+(/[^?\#]*)?$#i', $raw) || strlen($raw) > 255) {
                    return [null, "$label: the full address of the site, e.g. https://www.yourhotel.com (no ? or #)."];
                }
                return [rtrim($raw, '/'), null]; // stored without a trailing slash; consumers append /admin/... or /booking.php
            case 'url':
                if ($raw === '') return ['', null];
                return (filter_var($raw, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $raw) && strlen($raw) <= 500) ? [$raw, null] : [null, "$label: must be a full http(s) link."];
            case 'maps':
                if ($raw === '') return ['', null];
                // Accept the embed link or the whole <iframe> code; keep only a Google Maps embed URL
                // and store an iframe we build ourselves (contact-us.php prints this value as HTML).
                $src = preg_match('/src\s*=\s*["\']([^"\']+)["\']/i', $raw, $m) ? html_entity_decode($m[1]) : $raw;
                $parts = parse_url($src);
                $okHost = isset($parts['host']) && preg_match('/(^|\.)google\.[a-z.]+$/i', $parts['host']);
                if (!$okHost || ($parts['scheme'] ?? '') !== 'https' || strpos($parts['path'] ?? '', '/maps/embed') !== 0 || strlen($src) > 2000) {
                    return [null, "$label: paste the link or iframe code from Google Maps > Share > Embed a map."];
                }
                return ['<iframe src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" width="100%" height="380" style="border:0;" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map"></iframe>', null];
        }
        return [null, "$label: unsupported field."];
    }

    /**
     * Handle the form post. @return array{0:string,1:string} [message, error]
     */
    function rh_hotel_details_save(array $post, array $user): array
    {
        $uid = (int)($user['id'] ?? 0);
        $canFinance = hasPermission($uid, 'finance_settings');
        $errors = [];
        $changes = [];
        foreach (rh_hotel_details_fields() as $fields) {
            foreach ($fields as $key => $spec) {
                if (!array_key_exists($key, $post) || (!empty($spec['finance']) && !$canFinance)) {
                    continue; // finance fields are read-only without finance_settings
                }
                [$val, $err] = rh_hotel_details_clean($key, $spec, (string)$post[$key]);
                if ($err !== null) {
                    $errors[] = $err;
                    continue;
                }
                $current = rh_hotel_details_value($key, $spec);
                if ($spec['type'] === 'maps' && $val === '' && $current === '') {
                    continue;
                }
                if ($val !== $current) {
                    $changes[$key] = [$current, $val, $spec];
                }
            }
        }
        if ($errors) {
            return ['', implode(' ', $errors) . ' Nothing was saved.'];
        }
        foreach ($changes as $key => [$old, $new, $spec]) {
            updateSetting($key, $new);
            if (!empty($spec['mirror'])) {
                updateSetting($spec['mirror'], $new); // read under both names by older code
            }
        }
        if ($changes && function_exists('rh_log_event')) {
            $mask = static fn(string $k, string $v): string => ($k === 'bank_account_number' && strlen($v) > 4)
                ? str_repeat('*', strlen($v) - 4) . substr($v, -4) : mb_substr($v, 0, 120);
            $logged = [];
            foreach ($changes as $k => [$old, $new]) {
                $logged[$k] = ['from' => $mask($k, $old), 'to' => $mask($k, $new)];
            }
            rh_log_event('admin/booking-settings', 'info', 'Hotel details & policies changed', [
                'user' => $user['username'] ?? '', 'user_id' => $uid, 'changes' => $logged,
            ]);
        }
        return [$changes ? count($changes) . ' setting(s) saved.' : 'No changes to save.', ''];
    }

    /** Render the card. */
    function rh_hotel_details_render(array $user, string $csrf): void
    {
        $canFinance = hasPermission((int)($user['id'] ?? 0), 'finance_settings');
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        echo '<div class="rh-panel settings-card" id="hotel-details"><div class="rh-panel__head"><h2 class="rh-panel__title">Hotel details &amp; policies</h2></div>';
        echo '<form method="POST" action="booking-settings.php#hotel-details">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="save_hotel_details" value="1">';
        foreach (rh_hotel_details_fields() as $group => $fields) {
            echo '<h3>' . $e($group) . '</h3>';
            if ($group === 'Payments & documents' && !$canFinance) {
                echo '<p class="help-text"><i class="fas fa-lock"></i> Only users with the "Change VAT &amp; refund settings" permission can change these.</p>';
            }
            echo '<div class="bs-field-grid">';
            foreach ($fields as $key => $spec) {
                $id = 'hd_' . $key;
                $val = rh_hotel_details_value($key, $spec);
                if ($spec['type'] === 'maps' && preg_match('/src="([^"]+)"/', $val, $m)) {
                    $val = html_entity_decode($m[1]);
                }
                $dis = (!empty($spec['finance']) && !$canFinance) ? ' disabled' : '';
                $wide = in_array($spec['type'], ['textarea', 'maps'], true) ? ' data-wide="1"' : '';
                echo '<div class="form-group"' . $wide . '><label for="' . $id . '">' . $e($spec['label']) . '</label>';
                if ($spec['type'] === 'textarea') {
                    echo '<textarea id="' . $id . '" name="' . $key . '" rows="3" maxlength="' . (int)$spec['max'] . '"' . $dis . '>' . $e($val) . '</textarea>';
                } elseif ($spec['type'] === 'select') {
                    echo '<select id="' . $id . '" name="' . $key . '"' . $dis . '>';
                    foreach ($spec['options'] as $o) {
                        echo '<option value="' . $e($o) . '"' . ($o === $val ? ' selected' : '') . '>' . $e($o) . '</option>';
                    }
                    echo '</select>';
                } else {
                    $type = ['int' => 'number', 'decimal' => 'number', 'email' => 'email', 'url' => 'url', 'siteurl' => 'url'][$spec['type']] ?? 'text';
                    $attrs = '';
                    if (in_array($spec['type'], ['int', 'decimal'], true)) {
                        $attrs = ' min="' . $spec['min'] . '" max="' . $spec['max'] . '" step="' . ($spec['type'] === 'int' ? '1' : '0.01') . '"';
                    } elseif (isset($spec['max'])) {
                        $attrs = ' maxlength="' . (int)$spec['max'] . '"';
                    }
                    if ($spec['type'] === 'time12') {
                        $attrs .= ' placeholder="2:00 PM"';
                    }
                    echo '<input type="' . $type . '" id="' . $id . '" name="' . $key . '" value="' . $e($val) . '"' . $attrs . $dis . '>';
                }
                echo '</div>';
            }
            echo '</div>';
        }
        echo '<p class="help-text">Time zone: <strong>' . $e(date_default_timezone_get()) . '</strong> (set per installation with HOTEL_TIMEZONE in .env).</p>';
        echo '<button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Hotel Details</button></form></div>';
    }
}
