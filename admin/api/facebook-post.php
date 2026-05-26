<?php

/**
 * Admin API — Post to Facebook Page
 * Accepts: type (room|event), id (int), message (string), include_image (bool)
 *
 * Permission required: rooms_write (for rooms) or events (for events).
 * CSRF validated on every request.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../../includes/facebook-functions.php';

// Must be called from admin context
if (!defined('ADMIN_CONTEXT')) {
    define('ADMIN_CONTEXT', true);
}

require_once __DIR__ . '/../admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

sendSecurityHeaders();
header('Content-Type: application/json');

// ── Guards ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.', 'code' => 405]);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.', 'code' => 403]);
    exit;
}

if (getSetting('facebook_posting_enabled', '0') !== '1') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Facebook posting is not enabled.', 'code' => 403]);
    exit;
}

$type    = trim($_POST['type'] ?? '');
$id      = (int) ($_POST['id'] ?? 0);
$message = trim($_POST['message'] ?? '');
$includeImage = !empty($_POST['include_image']) && $_POST['include_image'] !== '0';

if (!in_array($type, ['room', 'event', 'conference', 'menu_item', 'rooms_all', 'conferences_all', 'gym_package', 'gym_packages_all', 'menu_share'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid type.', 'code' => 400]);
    exit;
}
// rooms_all / conferences_all / gym_packages_all / menu_share don't use a single ID
if (!in_array($type, ['rooms_all', 'conferences_all', 'gym_packages_all', 'menu_share'], true) && $id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid ID.', 'code' => 400]);
    exit;
}

// ── Permission check ───────────────────────────────────────────────────────
$required_permission = match ($type) {
    'room'             => 'rooms_write',
    'rooms_all'        => 'rooms_write',
    'conferences_all'  => 'conference',
    'event'            => 'events',
    'conference'       => 'conference',
    'menu_item'        => 'menu_management',
    'menu_share'       => 'menu_management',
    'gym_package'      => 'gym',
    'gym_packages_all' => 'gym',
    default            => 'admin',
};
if (function_exists('hasPermission') && !hasPermission($user['id'] ?? 0, $required_permission)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to post to Facebook.', 'code' => 403]);
    exit;
}

// ── Load record and build post ─────────────────────────────────────────────
/** @var array{message:string,link:string|null,image_url:string} $post */
$post = ['message' => '', 'link' => null, 'image_url' => ''];
try {
    if ($type === 'room') {
        if (getSetting('facebook_rooms_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Room posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, slug, short_description, description, price_per_night, image_url FROM rooms WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Room not found or is inactive.', 'code' => 404]);
            exit;
        }

        $post = buildRoomFacebookPost($record);
    } elseif ($type === 'event') {
        if (getSetting('facebook_events_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Event posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, title, description, event_date, start_time, image_path FROM events WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Event not found or is inactive.', 'code' => 404]);
            exit;
        }

        $post = buildEventFacebookPost($record);
    } elseif ($type === 'conference') {
        if (getSetting('facebook_conference_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Conference posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, description, daily_rate, capacity, size_sqm, amenities, image_path FROM conference_rooms WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conference room not found.', 'code' => 404]);
            exit;
        }

        $post = buildConferenceFacebookPost($record);
    } elseif ($type === 'menu_item') {
        if (getSetting('facebook_menu_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Menu posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $menuType = in_array(($_POST['menu_type'] ?? ''), ['food', 'drink'], true)
            ? $_POST['menu_type']
            : 'food';

        $table = $menuType === 'drink' ? 'drink_menu' : 'food_menu';
        $stmt = $pdo->prepare("SELECT id, item_name, description, price, category FROM {$table} WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Menu item not found.', 'code' => 404]);
            exit;
        }

        $post = buildMenuItemFacebookPost($record, $menuType);
    } elseif ($type === 'rooms_all') {
        if (getSetting('facebook_rooms_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Room posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        // Parse submitted room IDs (JSON array of strings/ints)
        $roomIdsRaw = trim($_POST['room_ids'] ?? '');
        $roomIds    = json_decode($roomIdsRaw, true);
        if (!is_array($roomIds)) {
            $roomIds = array_filter(array_map('intval', explode(',', $roomIdsRaw)));
        }
        $roomIds = array_values(array_filter(array_map('intval', $roomIds), fn($x) => $x > 0));

        if (empty($roomIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No room IDs provided.', 'code' => 400]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, name, slug, price_per_night, size_sqm, max_guests, image_url
               FROM rooms
              WHERE id IN ({$placeholders}) AND is_active = 1
              ORDER BY display_order"
        );
        $stmt->execute($roomIds);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No active rooms found for the given IDs.', 'code' => 404]);
            exit;
        }

        $baseUrl    = rtrim(defined('BASE_URL') ? BASE_URL : (string) getSetting('site_url', ''), '/');
        $currency   = (string) getSetting('currency_symbol', 'MWK');
        $hashtags   = (string) getSetting('facebook_default_hashtags', '#hotel #accommodation');
        $hotelName  = (string) getSetting('hotel_name', "Rosalyn's Beach Hotel");

        $lines = ["\u{1F3E8} {$hotelName} \u{2014} Our Rooms", ''];
        foreach ($records as $rec) {
            $price = number_format((float)($rec['price_per_night'] ?? 0), 0);
            $lines[] = "\u{1F6CF} {$rec['name']}";
            $parts   = [];
            if ($price !== '0') $parts[] = "{$currency} {$price}/night";
            if (!empty($rec['size_sqm']))   $parts[] = "{$rec['size_sqm']} sqm";
            if (!empty($rec['max_guests'])) $parts[] = "Max {$rec['max_guests']} guests";
            if (!empty($parts)) $lines[] = '   ' . implode(' \u00B7 ', $parts);
            $lines[] = '';
        }
        $lines[] = "\u{1F4C5} Book now: {$baseUrl}/rooms-showcase.php";
        $lines[] = '';
        $lines[] = $hashtags;

        // Pick featured image from first record that has one
        $featuredImageUrl = null;
        if ($includeImage) {
            foreach ($records as $rec) {
                if (!empty($rec['image_url'])) {
                    $img = $rec['image_url'];
                    if (!preg_match('#^https?://#i', $img)) {
                        $img = rtrim($baseUrl, '/') . '/' . ltrim($img, '/');
                    }
                    $featuredImageUrl = $img;
                    break;
                }
            }
        }

        $post = [
            'message'   => implode("\n", $lines),
            'link'      => $baseUrl . '/rooms-showcase.php',
            'image_url' => $featuredImageUrl ?? '',
        ];
    } elseif ($type === 'conferences_all') {
        if (getSetting('facebook_conference_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Conference posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $roomIdsRaw = trim($_POST['room_ids'] ?? '');
        $roomIds    = json_decode($roomIdsRaw, true);
        if (!is_array($roomIds)) {
            $roomIds = array_filter(array_map('intval', explode(',', $roomIdsRaw)));
        }
        $roomIds = array_values(array_filter(array_map('intval', $roomIds), fn($x) => $x > 0));

        if (empty($roomIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No conference room IDs provided.', 'code' => 400]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($roomIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, name, daily_rate, capacity, size_sqm, image_path
               FROM conference_rooms
              WHERE id IN ({$placeholders}) AND is_active = 1
              ORDER BY display_order"
        );
        $stmt->execute($roomIds);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No active conference rooms found for the given IDs.', 'code' => 404]);
            exit;
        }

        $baseUrl   = rtrim(defined('BASE_URL') ? BASE_URL : (string) getSetting('site_url', ''), '/');
        $currency  = (string) getSetting('currency_symbol', 'MWK');
        $hashtags  = (string) getSetting('facebook_default_hashtags', '#hotel #conference');
        $hotelName = (string) getSetting('hotel_name', "Rosalyn's Beach Hotel");

        $lines = ["\u{1F3E2} {$hotelName} \u{2014} Conference Facilities", ''];
        foreach ($records as $rec) {
            $rate = number_format((float)($rec['daily_rate'] ?? 0), 0);
            $lines[] = "\u{1F465} {$rec['name']}";
            $parts   = [];
            if ($rate !== '0')                  $parts[] = "{$currency} {$rate}/day";
            if (!empty($rec['capacity']))        $parts[] = "Up to {$rec['capacity']} guests";
            if (!empty($rec['size_sqm']))        $parts[] = "{$rec['size_sqm']} sqm";
            if (!empty($parts)) $lines[] = '   ' . implode(' \u00B7 ', $parts);
            $lines[] = '';
        }
        $lines[] = "\u{1F4CB} Enquire now: {$baseUrl}/conference.php";
        $lines[] = '';
        $lines[] = $hashtags;

        $featuredImageUrl = null;
        if ($includeImage) {
            foreach ($records as $rec) {
                if (!empty($rec['image_path'])) {
                    $img = $rec['image_path'];
                    if (!preg_match('#^https?://#i', $img)) {
                        $img = rtrim($baseUrl, '/') . '/' . ltrim($img, '/');
                    }
                    $featuredImageUrl = $img;
                    break;
                }
            }
        }

        $post = [
            'message'   => implode("\n", $lines),
            'link'      => $baseUrl . '/conference.php',
            'image_url' => $featuredImageUrl ?? '',
        ];
    } elseif ($type === 'gym_package') {
        if (getSetting('facebook_gym_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Gym posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id, name, icon_class, includes_text, duration_label, price, currency_code FROM gym_packages WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Gym package not found.', 'code' => 404]);
            exit;
        }

        $post = buildGymPackageFacebookPost($record);
    } elseif ($type === 'gym_packages_all') {
        if (getSetting('facebook_gym_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Gym posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $gymIdsRaw = [];
        if (isset($_POST['ids'])) {
            $gymIdsRaw = is_array($_POST['ids']) ? $_POST['ids'] : [$_POST['ids']];
        }
        $gymIds = array_values(array_filter(array_map('intval', $gymIdsRaw), fn($x) => $x > 0));

        if (empty($gymIds)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No gym package IDs provided.', 'code' => 400]);
            exit;
        }

        $placeholders = implode(',', array_fill(0, count($gymIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, name, price, currency_code, duration_label
               FROM gym_packages
              WHERE id IN ({$placeholders}) AND is_active = 1
              ORDER BY display_order"
        );
        $stmt->execute($gymIds);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($records)) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'No active gym packages found for the given IDs.', 'code' => 404]);
            exit;
        }

        $baseUrl   = rtrim(defined('BASE_URL') ? BASE_URL : (string) getSetting('site_url', ''), '/');
        $currency  = (string) getSetting('currency_symbol', 'MWK');
        $hashtags  = (string) getSetting('facebook_default_hashtags', '#hotel #wellness #gym');
        $hotelName = (string) getSetting('hotel_name', "Rosalyn's Beach Hotel");

        $lines = ["\u{1F3E8} Wellness Packages at {$hotelName}", ''];
        $lines[] = "Elevate your wellbeing with our exclusive packages:";
        $lines[] = '';
        foreach ($records as $rec) {
            $price = number_format((float)($rec['price'] ?? 0), 0);
            $curr  = trim((string)($rec['currency_code'] ?? $currency));
            $dur   = trim((string)($rec['duration_label'] ?? ''));
            $priceStr = "{$curr} {$price}" . ($dur !== '' ? " / {$dur}" : '');
            $lines[] = "\u{1F4AA} {$rec['name']} \u{2014} {$priceStr}";
        }
        $lines[] = '';
        $lines[] = "\u{27A1} {$baseUrl}/gym.php";
        $lines[] = '';
        $lines[] = $hashtags;

        $post = [
            'message'   => implode("\n", $lines),
            'link'      => $baseUrl . '/gym.php',
            'image_url' => '',
        ];
    } elseif ($type === 'menu_share') {
        if (getSetting('facebook_menu_enabled', '1') !== '1') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Menu posting is disabled in Facebook settings.', 'code' => 403]);
            exit;
        }

        $baseUrl  = rtrim(defined('BASE_URL') ? BASE_URL : (string) getSetting('site_url', ''), '/');
        $hashtags = (string) getSetting('facebook_default_hashtags', '#hotel #restaurant #food');

        $post = [
            'message'   => '',
            'link'      => $baseUrl . '/restaurant.php',
            'image_url' => '',
        ];
    }

    // Admin may override the generated message
    if ($message !== '') {
        $post['message'] = $message;
    }

    $imageUrl = $includeImage ? ($post['image_url'] ?? '') : '';
    if ($imageUrl === '') {
        $imageUrl = null;
    }

    $result = postFacebookFeed(
        $post['message'],
        $post['link'] ?? null,
        $imageUrl
    );

    rh_log_event('facebook', $result['success'] ? 'info' : 'warning', 'Admin Facebook post attempt', [
        'type'       => $type,
        'id'         => $id,
        'success'    => $result['success'],
        'post_id'    => $result['post_id'] ?? '',
        'posted_by'  => $user['username'] ?? 'unknown',
    ]);

    if ($result['success']) {
        $pageId = getSetting('facebook_page_id', '');
        $postUrl = '';
        if ($pageId !== '' && !empty($result['post_id'])) {
            $postUrl = 'https://www.facebook.com/' . $pageId . '/posts/' . explode('_', $result['post_id'])[1] ?? $result['post_id'];
        }
        echo json_encode([
            'success'  => true,
            'message'  => 'Posted to Facebook successfully.',
            'post_id'  => $result['post_id'],
            'post_url' => $postUrl,
        ]);
    } else {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error'   => $result['message'],
            'code'    => 502,
        ]);
    }
} catch (Throwable $e) {
    error_log('[admin/api/facebook-post] ' . $e->getMessage());
    rh_log_event('facebook', 'error', 'Exception during Facebook post', [
        'error'      => $e->getMessage(),
        'type'       => $type,
        'id'         => $id,
        'posted_by'  => $user['username'] ?? 'unknown',
    ]);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please check system logs.', 'code' => 500]);
}
