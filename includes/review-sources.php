<?php

/**
 * Review sources: API keys + Google lookups shared by the Hotel Settings card
 * (admin/includes/review-sources-settings.php) and the web importer
 * (admin/api/review-scraper.php).
 *
 * Keys live in site_settings (the live database), AES-256 encrypted with a key
 * derived from APP_ENCRYPTION_SALT, and are never sent to the browser: the
 * settings page only learns whether one is saved.
 *
 * Requires config/database.php (getSetting/updateSetting).
 */

/** Google's monthly free allowance for the Place Details SKU that returns reviews (check the Google Maps Platform pricing page for changes). */
if (!defined('RH_GOOGLE_FREE_LOOKUPS_PER_MONTH')) {
    define('RH_GOOGLE_FREE_LOOKUPS_PER_MONTH', 1000);
}

if (!function_exists('review_sources_key')) {

    function review_sources_key(): string
    {
        $salt = $_ENV['APP_ENCRYPTION_SALT'] ?? getenv('APP_ENCRYPTION_SALT') ?: 'DEFAULT_SALT_CHANGE_IN_ENV';
        return hash('sha256', 'review-sources|' . $salt, true);
    }

    function review_source_encrypt(string $plain): string
    {
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', review_sources_key(), OPENSSL_RAW_DATA, $iv);
        return 'rk1:' . base64_encode($iv . $cipher);
    }

    /** The decrypted key stored under $settingKey, or '' when none/unreadable. */
    function review_source_secret(string $settingKey): string
    {
        $stored = (string)getSetting($settingKey, '');
        if (strpos($stored, 'rk1:') !== 0) {
            return '';
        }
        $raw = base64_decode(substr($stored, 4), true);
        if ($raw === false || strlen($raw) <= 16) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', review_sources_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return is_string($plain) ? $plain : '';
    }

    /** @return array{google:bool,brave:bool,place_id:string} */
    function review_sources_status(): array
    {
        return [
            'google'   => review_source_secret('reviews_google_places_key') !== '',
            'brave'    => review_source_secret('reviews_brave_search_key') !== '',
            'place_id' => trim((string)getSetting('reviews_google_place_id', '')),
        ];
    }

    /** One JSON request (GET, or POST when $body is given). @return array{0:int,1:?array,2:string} [status, decoded, curl error] */
    function http_json(string $url, array $headers, ?array $body = null, int $timeout = 15): array
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
            CURLOPT_ENCODING => '',
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
            $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [$status, is_array($json) ? $json : null, $err];
    }

    /**
     * Resolve the hotel to a Google place.
     *
     * A saved Place ID is used as-is (no request). Otherwise a Text Search runs;
     * with $withDetails=false it asks for IDs only, which Google bills at $0
     * (unlimited), and with true it also returns the name/address (Pro SKU,
     * 5,000 free a month) so the admin can confirm it found the right hotel.
     *
     * @return array{id:string,name:string,address:string,uri:string}
     * @throws RuntimeException with Google's own message on failure
     */
    function review_google_find_place(string $apiKey, string $hotelName, string $location, string $placeIdOverride = '', bool $withDetails = false): array
    {
        $placeIdOverride = trim($placeIdOverride);
        if ($placeIdOverride !== '' && !$withDetails) {
            return ['id' => $placeIdOverride, 'name' => '', 'address' => '', 'uri' => ''];
        }

        $headers = ['X-Goog-Api-Key: ' . $apiKey];
        if ($placeIdOverride !== '') {
            [$st, $json, $err] = http_json(
                'https://places.googleapis.com/v1/places/' . rawurlencode($placeIdOverride),
                array_merge($headers, ['X-Goog-FieldMask: id,displayName,formattedAddress,googleMapsUri'])
            );
            if ($st !== 200 || !is_array($json) || empty($json['id'])) {
                throw new RuntimeException('Google: ' . ($json['error']['message'] ?? ($err ?: ('HTTP ' . $st))));
            }
            return [
                'id' => (string)$json['id'],
                'name' => (string)($json['displayName']['text'] ?? ''),
                'address' => (string)($json['formattedAddress'] ?? ''),
                'uri' => (string)($json['googleMapsUri'] ?? ''),
            ];
        }

        $mask = $withDetails ? 'places.id,places.displayName,places.formattedAddress,places.googleMapsUri' : 'places.id';
        [$st, $json, $err] = http_json(
            'https://places.googleapis.com/v1/places:searchText',
            array_merge($headers, ['X-Goog-FieldMask: ' . $mask]),
            ['textQuery' => trim($hotelName . ' ' . $location), 'languageCode' => 'en']
        );
        if ($st !== 200) {
            throw new RuntimeException('Google: ' . ($json['error']['message'] ?? ($err ?: ('HTTP ' . $st))));
        }
        $place = $json['places'][0] ?? null;
        if (!is_array($place) || empty($place['id'])) {
            throw new RuntimeException('Google could not find the hotel from its name and location. Paste its Place ID in Review sources to be exact.');
        }
        return [
            'id' => (string)$place['id'],
            'name' => (string)($place['displayName']['text'] ?? ''),
            'address' => (string)($place['formattedAddress'] ?? ''),
            'uri' => (string)($place['googleMapsUri'] ?? ''),
        ];
    }

    /** Count one billable Place Details call against this month's tally. */
    function review_google_count_call(): void
    {
        $key = 'reviews_google_calls_' . date('Ym');
        updateSetting($key, (string)((int)getSetting($key, '0') + 1));
    }

    function review_google_calls_this_month(): int
    {
        return (int)getSetting('reviews_google_calls_' . date('Ym'), '0');
    }
}
