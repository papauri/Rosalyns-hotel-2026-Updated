<?php

/**
 * Facebook Page Posting Functions
 * All Graph API calls are server-side PHP only — tokens are never exposed to the browser.
 */

if (!defined('FACEBOOK_GRAPH_API')) {
    define('FACEBOOK_GRAPH_API', 'https://graph.facebook.com/v19.0');
}

/**
 * Check if Facebook posting is enabled and fully configured.
 */
function isFacebookPostingEnabled(): bool
{
    return getSetting('facebook_posting_enabled', '0') === '1'
        && (string) getSetting('facebook_page_id', '') !== ''
        && (string) getSetting('facebook_page_access_token', '') !== '';
}

/**
 * Post a message + optional link or image to the Facebook Page feed.
 *
 * If $imageUrl is provided, uses the /photos endpoint (photo post with caption).
 * Otherwise uses the /feed endpoint (link post or plain text post).
 *
 * @param string      $message  Text / caption for the post.
 * @param string|null $link     Absolute public URL to attach (link preview). Ignored when $imageUrl is set.
 * @param string|null $imageUrl Absolute public URL of the image to post.
 * @return array{success:bool,message:string,post_id:string}
 */
function postFacebookFeed(string $message, ?string $link = null, ?string $imageUrl = null): array
{
    $pageId   = (string) getSetting('facebook_page_id', '');
    $rawToken = (string) getSetting('facebook_page_access_token', '');
    // Decrypt token if stored encrypted (backward-compatible: falls back to raw value)
    $token = ($rawToken !== '' && function_exists('decryptApiKey'))
        ? (decryptApiKey($rawToken) ?? $rawToken)
        : $rawToken;

    if ($pageId === '' || $token === '') {
        return [
            'success'  => false,
            'message'  => 'Facebook Page ID or Access Token is not configured.',
            'post_id'  => '',
        ];
    }

    if ($imageUrl !== null && $imageUrl !== '') {
        $endpoint = FACEBOOK_GRAPH_API . '/' . $pageId . '/photos';
        $payload  = [
            'url'          => $imageUrl,
            'caption'      => $message,
            'access_token' => $token,
        ];
    } else {
        $endpoint = FACEBOOK_GRAPH_API . '/' . $pageId . '/feed';
        $payload  = [
            'message'      => $message,
            'access_token' => $token,
        ];
        if ($link !== null && $link !== '') {
            $payload['link'] = $link;
        }
    }

    // Resolve CA bundle: php.ini curl.cainfo > project config > skip (production OS handles it)
    $caBundle = (string) ini_get('curl.cainfo');
    if ($caBundle === '' || !file_exists($caBundle)) {
        $localCa = dirname(__DIR__) . '/config/cacert.pem';
        if (file_exists($localCa)) {
            $caBundle = $localCa;
        }
    }

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => $endpoint,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ];
    if ($caBundle !== '') {
        $curlOpts[CURLOPT_CAINFO] = $caBundle;
    }
    curl_setopt_array($ch, $curlOpts);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        rh_log_event('facebook', 'error', 'cURL error posting to Facebook', ['error' => $curlErr]);
        return ['success' => false, 'message' => 'Network error: ' . $curlErr, 'post_id' => ''];
    }

    $decoded = json_decode((string) $response, true);

    if ($httpCode >= 200 && $httpCode < 300 && !empty($decoded['id'])) {
        $postId = (string) $decoded['id'];
        rh_log_event('facebook', 'info', 'Facebook post published', [
            'post_id'   => $postId,
            'has_image' => ($imageUrl !== null && $imageUrl !== ''),
            'has_link'  => ($link !== null && $link !== ''),
        ]);
        return ['success' => true, 'message' => 'Posted successfully.', 'post_id' => $postId];
    }

    $fbError = (string) ($decoded['error']['message'] ?? ('HTTP ' . $httpCode));
    rh_log_event('facebook', 'warning', 'Facebook post failed', [
        'http_code' => $httpCode,
        'fb_error'  => $fbError,
    ]);
    return ['success' => false, 'message' => 'Facebook API error: ' . $fbError, 'post_id' => ''];
}

/**
 * Build post content array for a room.
 *
 * @param  array $room Row from the rooms table (must include: name, slug, short_description, description, price_per_night, image_url).
 * @return array{message:string,link:string,image_url:string}
 */
function buildRoomFacebookPost(array $room): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury'));

    $slug     = (string) ($room['slug'] ?? '');
    $link     = $baseUrl . '/room.php?room=' . urlencode($slug);

    $priceLine = '';
    if (!empty($room['price_per_night'])) {
        $priceLine = "\nFrom " . $currency . ' ' . number_format((float) $room['price_per_night']) . '/night';
    }

    $blurb = trim((string) ($room['short_description'] ?? $room['description'] ?? ''));

    $message = trim(
        ($room['name'] ?? 'Room') . "\n"
            . ($blurb !== '' ? $blurb . "\n" : '')
            . $priceLine . "\n\n"
            . 'Book now: ' . $link . "\n\n"
            . $hashtags
    );

    $imageUrl = '';
    if (!empty($room['image_url'])) {
        $img      = (string) $room['image_url'];
        $imageUrl = preg_match('#^https?://#', $img) ? $img : $baseUrl . '/' . ltrim($img, '/');
    }

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => $imageUrl,
    ];
}

/**
 * Build post content array for an event.
 *
 * @param  array $event Row from the events table (must include: id, title, description, event_date, start_time, image_path).
 * @return array{message:string,link:string,image_url:string}
 */
function buildEventFacebookPost(array $event): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #events'));

    $datePart = '';
    if (!empty($event['event_date'])) {
        try {
            $dt = new DateTime((string) $event['event_date'], new DateTimeZone('Africa/Blantyre'));
            $datePart = "\nDate: " . $dt->format('l, F j, Y');
        } catch (Exception $e) {
            $datePart = '';
        }
    }
    if (!empty($event['start_time'])) {
        $datePart .= ' at ' . date('g:i A', strtotime((string) $event['start_time']));
    }

    $link = $baseUrl . '/events.php#event-' . ($event['id'] ?? '');

    $imageUrl = '';
    if (!empty($event['image_path'])) {
        $img      = (string) $event['image_path'];
        $imageUrl = preg_match('#^https?://#', $img) ? $img : $baseUrl . '/' . ltrim($img, '/');
    }

    $blurb = trim((string) ($event['description'] ?? ''));

    $message = trim(
        ($event['title'] ?? 'Event') . "\n"
            . ($blurb !== '' ? $blurb . "\n" : '')
            . $datePart . "\n\n"
            . 'Full details: ' . $link . "\n\n"
            . $hashtags
    );

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => $imageUrl,
    ];
}

/**
 * Build post content array for a conference room.
 *
 * @param  array $room Row from conference_rooms (id, name, description, daily_rate, capacity, size_sqm, amenities, image_path).
 * @return array{message:string,link:string,image_url:string}
 */
function buildConferenceFacebookPost(array $room): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #conference #events'));

    $link = $baseUrl . '/conference.php';

    $lines = [];
    $lines[] = '🏢 ' . ($room['name'] ?? 'Conference Room');

    $blurb = trim((string) ($room['description'] ?? ''));
    if ($blurb !== '') {
        $lines[] = $blurb;
    }

    $details = [];
    if (!empty($room['capacity'])) {
        $details[] = 'Capacity: ' . (int) $room['capacity'] . ' guests';
    }
    if (!empty($room['size_sqm'])) {
        $details[] = 'Size: ' . number_format((float) $room['size_sqm'], 0) . ' sqm';
    }
    if (!empty($room['daily_rate'])) {
        $details[] = 'Rate: ' . $currency . ' ' . number_format((float) $room['daily_rate']) . '/day';
    }
    if (!empty($details)) {
        $lines[] = implode(' · ', $details);
    }

    if (!empty($room['amenities'])) {
        $lines[] = 'Facilities: ' . $room['amenities'];
    }

    $lines[] = '';
    $lines[] = 'Book your next event: ' . $link;
    $lines[] = '';
    $lines[] = $hashtags;

    $message = trim(implode("\n", $lines));

    $imageUrl = '';
    if (!empty($room['image_path'])) {
        $img      = (string) $room['image_path'];
        $imageUrl = preg_match('#^https?://#', $img) ? $img : $baseUrl . '/' . ltrim($img, '/');
    }

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => $imageUrl,
    ];
}

/**
 * Build post content array for a menu item (food or drink).
 *
 * @param  array  $item      Row from food_menu or drink_menu (id, item_name, description, price, category).
 * @param  string $menuType  'food' or 'drink'.
 * @return array{message:string,link:string,image_url:string}
 */
function buildMenuItemFacebookPost(array $item, string $menuType = 'food'): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #restaurant #food'));

    $icon = $menuType === 'drink' ? '🍹' : '🍽️';
    $link = $baseUrl . '/restaurant.php';

    $lines = [];
    $lines[] = $icon . ' ' . ($item['item_name'] ?? 'Menu Item');

    $blurb = trim((string) ($item['description'] ?? ''));
    if ($blurb !== '') {
        $lines[] = $blurb;
    }

    if (!empty($item['price'])) {
        $lines[] = $currency . ' ' . number_format((float) $item['price']);
    }

    if (!empty($item['category'])) {
        $lines[] = 'Category: ' . $item['category'];
    }

    $lines[] = '';
    $lines[] = 'See our full menu: ' . $link;
    $lines[] = '';
    $lines[] = $hashtags;

    $message = trim(implode("\n", $lines));

    return [
        'message'   => $message,
        'link'      => $link,
        'image_url' => '',
    ];
}

/**
 * Build a Facebook post for a single gym package.
 *
 * @param array $package Row from gym_packages table.
 * @return array{message:string,link:string,image_url:string}
 */
function buildGymPackageFacebookPost(array $package): array
{
    $baseUrl  = defined('BASE_URL') ? rtrim(BASE_URL, '/') : '';
    $currency = (string) getSetting('currency_symbol', 'MWK');
    $hashtags = trim((string) getSetting('facebook_default_hashtags', '#hotel #wellness #gym'));

    $link   = $baseUrl . '/gym.php';
    $price  = (float) ($package['price'] ?? 0);
    $dur    = trim((string) ($package['duration_label'] ?? ''));
    $name   = trim((string) ($package['name'] ?? 'Wellness Package'));

    $lines   = [];
    $lines[] = "\ud83d\udcaa " . $name;

    if ($price > 0) {
        $priceStr = $currency . ' ' . number_format($price);
        if ($dur !== '') {
            $priceStr .= ' / ' . $dur;
        }
        $lines[] = $priceStr;
    }

    $includes = trim((string) ($package['includes_text'] ?? ''));
    if ($includes !== '') {
        $items = array_filter(array_map('trim', explode("\n", $includes)));
        if (!empty($items)) {
            $lines[] = '';
            $lines[] = 'Includes:';
            foreach (array_slice($items, 0, 5) as $b) {
                $lines[] = "\u2714 " . $b;
            }
        }
    }

    $lines[] = '';
    $lines[] = "\ud83c\udfe8 " . $link;
    $lines[] = '';
    $lines[] = $hashtags;

    return [
        'message'   => trim(implode("\n", $lines)),
        'link'      => $link,
        'image_url' => '',
    ];
}
