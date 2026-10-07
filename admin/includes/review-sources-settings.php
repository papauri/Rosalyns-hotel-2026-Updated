<?php

/**
 * Admin -> Hotel Settings -> "Review sources".
 *
 * Where the Google Places and Brave Search API keys for the Reviews web importer
 * are kept. Saved straight into site_settings (the live database), encrypted; the
 * page only ever learns whether a key exists, never what it is.
 */

require_once __DIR__ . '/../../includes/review-sources.php';

if (!function_exists('rh_review_sources_save')) {

    /**
     * Handle the form post.
     * @return array{0:string,1:string} [message (HTML-safe), error (plain text)]
     */
    function rh_review_sources_save(array $post, array $user): array
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $errors = [];
        $done = [];

        $keys = [
            'google' => ['reviews_google_places_key', 'Google Places API key'],
            'brave'  => ['reviews_brave_search_key', 'Brave Search API key'],
        ];
        foreach ($keys as $field => [$settingKey, $label]) {
            if (!empty($post[$field . '_remove'])) {
                updateSetting($settingKey, '');
                $done[] = $label . ' removed';
                continue;
            }
            $val = trim(str_replace("\0", '', (string)($post[$field] ?? '')));
            if ($val === '') {
                continue; // blank keeps what is saved
            }
            if (strlen($val) < 20 || strlen($val) > 300 || preg_match('/\s/', $val)) {
                $errors[] = $label . ': that does not look like a key (20 to 300 characters, no spaces).';
                continue;
            }
            updateSetting($settingKey, review_source_encrypt($val));
            $done[] = $label . ' saved';
        }

        // Optional exact place, so the search never has to guess the hotel from its name.
        if (array_key_exists('google_place_id', $post)) {
            $pid = trim((string)$post['google_place_id']);
            $pid = preg_replace('#^places/#i', '', $pid);
            if ($pid !== '' && !preg_match('/^[A-Za-z0-9_-]{10,200}$/', $pid)) {
                $errors[] = 'Google Place ID: letters, digits, - and _ only (it usually starts with ChIJ).';
            } elseif ($pid !== (string)getSetting('reviews_google_place_id', '')) {
                updateSetting('reviews_google_place_id', $pid);
                $done[] = $pid === '' ? 'Place ID cleared' : 'Place ID saved';
            }
        }

        if ($errors) {
            return ['', implode(' ', $errors) . ($done ? ' (Other changes were saved.)' : ' Nothing was saved.')];
        }

        $lines = [];
        if ($done) {
            $lines[] = $e(ucfirst(implode(', ', $done))) . '.';
            if (function_exists('rh_log_event')) {
                rh_log_event('admin/booking-settings', 'info', 'Review sources changed', [
                    'user' => $user['username'] ?? '', 'user_id' => (int)($user['id'] ?? 0), 'changes' => $done,
                ]);
            }
        }

        if (!empty($post['test_review_sources'])) {
            foreach (rh_review_sources_test() as $line) {
                $lines[] = $line;
            }
        } elseif (!$done) {
            $lines[] = 'No changes to save.';
        }
        return [implode('<br>', $lines), ''];
    }

    /** Live-check each saved key. @return string[] HTML-safe result lines */
    function rh_review_sources_test(): array
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $out = [];

        $g = review_source_secret('reviews_google_places_key');
        if ($g === '') {
            $out[] = '<strong>Google:</strong> no key saved, so there was nothing to test.';
        } else {
            try {
                $place = review_google_find_place(
                    $g,
                    (string)getSetting('site_name', ''),
                    trim(getSetting('address_line2', '') . ' ' . getSetting('address_country', '')),
                    (string)getSetting('reviews_google_place_id', ''),
                    true
                );
                $out[] = '<strong>Google works.</strong> It matched <strong>' . $e($place['name'] ?: 'your place') . '</strong>'
                    . ($place['address'] !== '' ? ' &mdash; ' . $e($place['address']) : '')
                    . '. If that is not your hotel, paste the right Place ID below.';
            } catch (Throwable $ex) {
                $out[] = '<strong>Google did not accept the key:</strong> ' . $e($ex->getMessage())
                    . ' (Check that &ldquo;Places API (New)&rdquo; is enabled for the key&rsquo;s project and billing is on.)';
            }
        }

        $b = review_source_secret('reviews_brave_search_key');
        if ($b === '') {
            $out[] = '<strong>Brave:</strong> no key saved, so there was nothing to test.';
        } else {
            [$st, $json, $err] = http_json('https://api.search.brave.com/res/v1/web/search?count=1&q=hotel', ['X-Subscription-Token: ' . $b]);
            $out[] = $st === 200
                ? '<strong>Brave works.</strong>'
                : '<strong>Brave did not accept the key:</strong> ' . $e($json['error']['detail'] ?? ($err ?: ('HTTP ' . $st)));
        }
        return $out;
    }

    /** Render the card. */
    function rh_review_sources_render(array $user, string $csrf): void
    {
        $e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $st = review_sources_status();
        $calls = review_google_calls_this_month();
        $free = RH_GOOGLE_FREE_LOOKUPS_PER_MONTH;
        $badge = static fn(bool $on): string => $on
            ? '<span class="badge badge-success">Connected</span>'
            : '<span class="badge badge-neutral">Not set up</span>';

        echo '<div class="rh-panel settings-card" id="review-sources"><div class="rh-panel__head"><h2 class="rh-panel__title">Review sources (web importer)</h2></div>';
        echo '<p class="help-text">Used by <a href="reviews.php">Reviews &rarr; Import reviews from the web</a>. Keys are saved encrypted in the live database and are never shown again; leave a box empty to keep the saved key.</p>';
        echo '<form method="POST" action="booking-settings.php#review-sources" autocomplete="off">';
        echo '<input type="hidden" name="csrf_token" value="' . $e($csrf) . '"><input type="hidden" name="save_review_sources" value="1">';

        echo '<h3>Google reviews ' . $badge($st['google']) . '</h3>';
        echo '<p class="help-text">Brings in real Google reviews with the reviewer&rsquo;s name, stars and exact post date (Google shares up to 5 &ldquo;most relevant&rdquo; reviews per place). '
            . 'Create a key in <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">Google Cloud Console</a> and enable <em>Places API (New)</em>.</p>';
        echo '<div class="bs-field-grid">';
        echo '<div class="form-group"><label for="rs_google">Google Places API key</label>'
            . '<input type="password" id="rs_google" name="google" maxlength="300" autocomplete="new-password" placeholder="' . ($st['google'] ? 'Saved — type to replace' : 'AIza…') . '"></div>';
        echo '<div class="form-group"><label for="rs_place">Google Place ID <small>(optional — exact hotel)</small></label>'
            . '<input type="text" id="rs_place" name="google_place_id" maxlength="210" value="' . $e($st['place_id']) . '" placeholder="ChIJ…"></div>';
        echo '</div>';
        if ($st['google']) {
            echo '<label class="help-text"><input type="checkbox" name="google_remove" value="1"> Remove the saved Google key</label>';
        }
        echo '<p class="help-text"><i class="fas fa-receipt"></i> Usage this month: <strong>' . (int)$calls . '</strong> of about ' . number_format($free)
            . ' free lookups. One lookup per search (repeat searches within 6 hours reuse the last result). Finding the hotel costs nothing.</p>';

        echo '<h3>Brave Search ' . $badge($st['brave']) . ' <small>(optional)</small></h3>';
        echo '<p class="help-text">Reliable web and social-media mentions with each page&rsquo;s own date. Free plan at <a href="https://api-dashboard.search.brave.com/" target="_blank" rel="noopener noreferrer">api-dashboard.search.brave.com</a>.</p>';
        echo '<div class="bs-field-grid"><div class="form-group"><label for="rs_brave">Brave Search API key</label>'
            . '<input type="password" id="rs_brave" name="brave" maxlength="300" autocomplete="new-password" placeholder="' . ($st['brave'] ? 'Saved — type to replace' : 'BSA…') . '"></div></div>';
        if ($st['brave']) {
            echo '<label class="help-text"><input type="checkbox" name="brave_remove" value="1"> Remove the saved Brave key</label>';
        }

        echo '<div class="bs-actions" style="display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;">';
        echo '<button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save</button>';
        echo '<button type="submit" name="test_review_sources" value="1" class="btn-submit"><i class="fas fa-plug"></i> Save &amp; test connection</button>';
        echo '</div></form></div>';
    }
}
