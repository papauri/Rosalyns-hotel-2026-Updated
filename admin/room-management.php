<?php

/**
 * Room Management - Admin Panel
 * Card-based layout with modal editing and drag-and-drop ordering
 */
require_once 'admin-init.php';
/** @var string $csrf_token */

$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!$is_ajax) {
    require_once '../includes/alert.php';
}
require_once 'video-upload-handler.php';
require_once __DIR__ . '/../includes/form-validation.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/includes/child-charge-fields.php';

function syncRoomManagedMedia(array $room): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $roomId = $room['id'] ?? null;
    if (!$roomId) {
        return;
    }

    upsertManagedMediaForSource('rooms', $roomId, 'image_url', $room['image_url'] ?? null, [
        'title' => ($room['name'] ?? 'Room') . ' (Image)',
        'description' => $room['short_description'] ?? ($room['description'] ?? null),
        'caption' => $room['short_description'] ?? null,
        'alt_text' => $room['name'] ?? 'Room image',
        'placement_key' => 'rooms.image_url',
        'page_slug' => 'rooms-gallery',
        'section_key' => 'rooms_collection',
        'entity_type' => 'room',
        'entity_id' => (int)$roomId,
        'display_order' => (int)($room['display_order'] ?? 0),
        'use_case' => 'featured_image',
        'media_type' => 'image',
    ]);

    upsertManagedMediaForSource('rooms', $roomId, 'video_path', $room['video_path'] ?? null, [
        'title' => ($room['name'] ?? 'Room') . ' (Video)',
        'description' => $room['short_description'] ?? ($room['description'] ?? null),
        'caption' => $room['short_description'] ?? null,
        'alt_text' => $room['name'] ?? 'Room video',
        'placement_key' => 'rooms.video_path',
        'page_slug' => 'room',
        'section_key' => 'rooms_hero',
        'entity_type' => 'room',
        'entity_id' => (int)$roomId,
        'display_order' => (int)($room['display_order'] ?? 0),
        'use_case' => 'room_video',
        'media_type' => 'video',
        'mime_type' => $room['video_type'] ?? null,
    ]);
}

// Helpers
function ini_bytes(mixed $val): int
{
    $val = trim((string)$val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;
    switch ($last) {
        case 'g':
            $num *= 1024;
        case 'm':
            $num *= 1024;
        case 'k':
            $num *= 1024;
    }
    return $num;
}
function is_ajax_request()
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function normalizeRoomImagePath(mixed $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^(javascript|data):/i', $value)) {
        return null;
    }

    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }

    return ltrim(str_replace('\\', '/', $value), '/');
}

function getRoomsImageUploadConfig(): array
{
    $base = 'images/rooms';
    if (function_exists('getSetting')) {
        $configured = trim((string)getSetting('rooms_image_upload_dir', ''));
        if ($configured !== '') {
            $base = $configured;
        }
    }

    $base = str_replace('\\', '/', $base);
    $base = trim($base, '/');
    if ($base === '' || preg_match('/\.\./', $base)) {
        $base = 'images/rooms';
    }

    return [
        'db_prefix' => $base,
        'fs_dir' => '../' . $base . '/',
    ];
}

/** Posted number -> float (blank/non-numeric -> $default, negatives clamped to 0) so strict SQL never sees ''. */
function rm_num($v, $default = 0.0)
{
    $v = is_string($v) ? trim(str_replace(',', '', $v)) : $v;
    if ($v === '' || $v === null || !is_numeric($v)) {
        return $default;
    }
    return max(0.0, (float)$v);
}

/**
 * Server-side checks shared by add_room and update. Returns an error string or null.
 * $total / $avail are the already-parsed integers.
 */
function rm_validate_room_input(PDO $pdo, string $name, int $total, int $avail, ?int $excludeId): ?string
{
    if ($name === '') {
        return 'Room name is required.';
    }
    if (mb_strlen($name) > 100) {
        return 'Room name is too long (max 100 characters).';
    }
    $price = $_POST['price_per_night'] ?? '';
    $priceStr = is_string($price) ? trim(str_replace(',', '', $price)) : $price;
    if ($priceStr === '' || !is_numeric($priceStr) || (float)$priceStr <= 0) {
        return 'Price per night is required and must be more than 0.';
    }
    $guests = trim((string)($_POST['max_guests'] ?? '2'));
    if (!ctype_digit($guests) || (int)$guests < 1 || (int)$guests > 20) {
        return 'Maximum guests must be a whole number between 1 and 20.';
    }
    if (mb_strlen((string)($_POST['short_description'] ?? '')) > 255) {
        return 'Short description is too long (max 255 characters).';
    }
    $bed = rh_clean_text($_POST['bed_type'] ?? '');
    if (mb_strlen($bed) > 50) {
        return 'Bed type is too long (max 50 characters).';
    }
    if ($total < 1) {
        return 'Total rooms must be at least 1.';
    }
    if ($avail < 0 || $avail > $total) {
        return 'Availability cannot exceed total rooms.';
    }
    foreach (['price_single_occupancy', 'price_double_occupancy', 'price_triple_occupancy', 'size_sqm'] as $f) {
        $v = $_POST[$f] ?? '';
        $v = is_string($v) ? trim(str_replace(',', '', $v)) : $v;
        if ($v !== '' && $v !== null && (!is_numeric($v) || (float)$v < 0)) {
            return 'Prices and room size must be numbers of 0 or more.';
        }
    }
    $cm = $_POST['child_price_multiplier'] ?? '';
    if ($cm !== '' && (!is_numeric($cm) || (float)$cm < 0 || (float)$cm > 100)) {
        return 'Child price percentage must be between 0 and 100.';
    }
    $childRulePost = rh_child_rule_post($_POST, false);
    if ($childRulePost['error'] !== null) {
        return $childRulePost['error'];
    }
    $dup = rh_find_duplicate($pdo, 'rooms', 'name', $name, [], $excludeId);
    if ($dup) {
        return rh_duplicate_message('room type', (string)$dup['value']);
    }
    return null;
}

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'update') {
            $rooms_available = (int)($_POST['rooms_available'] ?? 0);
            $total_rooms = (int)($_POST['total_rooms'] ?? 0);
            $room_image_url = normalizeRoomImagePath($_POST['image_url'] ?? '');

            $_POST['name'] = rh_clean_text($_POST['name'] ?? '');
            $validationError = (int)($_POST['id'] ?? 0) <= 0
                ? 'Room not found.'
                : rm_validate_room_input($pdo, (string)$_POST['name'], $total_rooms, $rooms_available, (int)$_POST['id']);
            if ($validationError !== null) {
                $error = $validationError;
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
            } else {
                $description = !empty($_POST['description']) ? $_POST['description'] : ($_POST['short_description'] ?? '');

                $stmt = $pdo->prepare("
                    UPDATE rooms
                    SET name = ?, description = ?, short_description = ?, price_per_night = ?,
                        price_single_occupancy = ?, price_double_occupancy = ?, price_triple_occupancy = ?, child_price_multiplier = ?,
                        child_charge_mode = ?, child_charge_amount = ?, free_children = ?, max_children = ?,
                        single_occupancy_enabled = ?, double_occupancy_enabled = ?, triple_occupancy_enabled = ?, children_allowed = ?,
                        size_sqm = ?, max_guests = ?, rooms_available = ?, total_rooms = ?,
                        bed_type = ?, amenities = ?, image_url = ?, is_featured = ?, is_active = ?, display_order = ?
                    WHERE id = ?
                ");
                $maxGuests = max(1, (int)($_POST['max_guests'] ?? 2));
                $singleEnabled = $maxGuests >= 1 ? 1 : 0;
                $doubleEnabled = $maxGuests >= 2 ? 1 : 0;
                $tripleEnabled = ($maxGuests >= 3 && isset($_POST['triple_occupancy_enabled'])) ? 1 : 0;
                $childrenAllowed = isset($_POST['children_allowed']) ? 1 : 0;
                $cc = rh_child_rule_post($_POST, false);   // validated above
                $stmt->execute([
                    trim((string)$_POST['name']),
                    $description,
                    $_POST['short_description'] ?? '',
                    rm_num($_POST['price_per_night'] ?? 0),
                    rm_num($_POST['price_single_occupancy'] ?? null, null),
                    rm_num($_POST['price_double_occupancy'] ?? null, null),
                    rm_num($_POST['price_triple_occupancy'] ?? null, null),
                    $cc['percent'],
                    $cc['mode'],
                    $cc['amount'],
                    $cc['free'],
                    $cc['max'],
                    $singleEnabled,
                    $doubleEnabled,
                    $tripleEnabled,
                    $childrenAllowed,
                    rm_num($_POST['size_sqm'] ?? 0),
                    $maxGuests,
                    $rooms_available,
                    $total_rooms,
                    $_POST['bed_type'] ?? 'Double',
                    $_POST['amenities'] ?? '',
                    $room_image_url,
                    isset($_POST['is_featured']) ? 1 : 0,
                    isset($_POST['is_active']) ? 1 : 0,
                    (int)($_POST['display_order'] ?? 0),
                    (int)$_POST['id']
                ]);

                $mediaSyncStmt = $pdo->prepare("SELECT id, name, description, short_description, display_order, image_url, video_path, video_type FROM rooms WHERE id = ? LIMIT 1");
                $mediaSyncStmt->execute([$_POST['id']]);
                $roomForMedia = $mediaSyncStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomForMedia) {
                    syncRoomManagedMedia($roomForMedia);
                }

                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room updated successfully!';

                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => $message]);
                    exit;
                }
            }
        } elseif ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE rooms SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'Room status updated!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        } elseif ($action === 'toggle_featured') {
            $stmt = $pdo->prepare("UPDATE rooms SET is_featured = NOT is_featured WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'Featured status updated!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        } elseif ($action === 'upload_image') {
            if (isset($_FILES['room_image']) && $_FILES['room_image']['error'] === 0) {
                $room_id = (int)($_POST['room_id'] ?? 0);

                // Extension comes from the verified MIME type, never the client file name.
                $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $ext = '';
                $mime_ok = false;
                if (function_exists('finfo_open')) {
                    $f = finfo_open(FILEINFO_MIME_TYPE);
                    if ($f) {
                        $mime = (string)finfo_file($f, $_FILES['room_image']['tmp_name']);
                        finfo_close($f);
                        $mime_ok = isset($mimeToExt[$mime]) && @getimagesize($_FILES['room_image']['tmp_name']);
                        $ext = $mimeToExt[$mime] ?? '';
                    }
                }
                $roomExists = false;
                if ($room_id > 0) {
                    $rx = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE id = ?');
                    $rx->execute([$room_id]);
                    $roomExists = (int)$rx->fetchColumn() > 0;
                }

                // Shared size cap (config/security.php); an oversize file is rejected, not uploaded anyway.
                $rm_sizeError = rh_check_image_upload_size($_FILES['room_image'], $rm_sizeWarning);
                if ($rm_sizeError) {
                    $error = $rm_sizeError;
                    if (is_ajax_request()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $error]);
                        exit;
                    }
                } elseif (!$roomExists) {
                    $error = 'Room not found.';
                    if (is_ajax_request()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $error]);
                        exit;
                    }
                } elseif ($ext !== '' && $mime_ok) {
                    $uploadConfig = getRoomsImageUploadConfig();
                    $upload_dir = $uploadConfig['fs_dir'];
                    if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                    $new_filename = 'room_' . $room_id . '_featured_' . time() . '.' . $ext;
                    $upload_path = $upload_dir . $new_filename;

                    if (move_uploaded_file($_FILES['room_image']['tmp_name'], $upload_path)) {
                        $image_url = rtrim($uploadConfig['db_prefix'], '/') . '/' . $new_filename;

                        $oldStmt = $pdo->prepare("SELECT image_url FROM rooms WHERE id = ?");
                        $oldStmt->execute([$room_id]);
                        $old_image = $oldStmt->fetchColumn();

                        $stmt = $pdo->prepare("UPDATE rooms SET image_url = ? WHERE id = ?");
                        $stmt->execute([$image_url, $room_id]);

                        $mediaSyncStmt = $pdo->prepare("SELECT id, name, description, short_description, display_order, image_url, video_path, video_type FROM rooms WHERE id = ? LIMIT 1");
                        $mediaSyncStmt->execute([$room_id]);
                        $roomForMedia = $mediaSyncStmt->fetch(PDO::FETCH_ASSOC);
                        if ($roomForMedia) {
                            syncRoomManagedMedia($roomForMedia);
                        }

                        $message = 'Room image uploaded successfully!';

                        if ($old_image && !preg_match('#^https?://#i', $old_image)) {
                            $old_path = '../' . $old_image;
                            if (file_exists($old_path)) @unlink($old_path);
                        }

                        require_once __DIR__ . '/../config/cache.php';
                        clearRoomCache();

                        if (is_ajax_request()) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => true, 'image_url' => $image_url]);
                            exit;
                        }
                    } else {
                        $error = 'Failed to upload image.';
                        if (is_ajax_request()) {
                            header('Content-Type: application/json');
                            echo json_encode(['success' => false, 'message' => $error]);
                            exit;
                        }
                    }
                } else {
                    $error = 'Invalid file type. Only JPG, PNG, and WEBP allowed.';
                    if (is_ajax_request()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $error]);
                        exit;
                    }
                }
            } else {
                $contentLen = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
                $postMax = ini_bytes(ini_get('post_max_size'));
                if ($contentLen > 0 && $postMax > 0 && $contentLen >= $postMax) {
                    $error = 'Upload too large. Server POST limit is ' . ini_get('post_max_size') . '.';
                } else {
                    $err = $_FILES['room_image']['error'] ?? 0;
                    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) $error = 'File too large.';
                    elseif ($err === UPLOAD_ERR_NO_FILE) $error = 'No image selected.';
                    else $error = 'Upload error (code ' . $err . ').';
                }
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
            }
        } elseif ($action === 'update_image_url') {
            $room_id = (int)($_POST['room_id'] ?? 0);
            $image_url = normalizeRoomImagePath($_POST['image_url_input'] ?? '');

            if ($room_id <= 0) {
                $error = 'Invalid room selected.';
            } elseif ($image_url === null) {
                $error = 'Please provide a valid image URL or relative image path.';
            } else {
                $stmt = $pdo->prepare("UPDATE rooms SET image_url = ? WHERE id = ?");
                $stmt->execute([$image_url, $room_id]);

                $mediaSyncStmt = $pdo->prepare("SELECT id, name, description, short_description, display_order, image_url, video_path, video_type FROM rooms WHERE id = ? LIMIT 1");
                $mediaSyncStmt->execute([$room_id]);
                $roomForMedia = $mediaSyncStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomForMedia) {
                    syncRoomManagedMedia($roomForMedia);
                }

                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room image URL/path updated successfully!';

                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'image_url' => $image_url, 'message' => $message]);
                    exit;
                }
            }

            if (!empty($error) && is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
        } elseif ($action === 'add_room') {
            $_POST['name'] = rh_clean_text($_POST['name'] ?? '');
            $addTotal = (int)($_POST['total_rooms'] ?? 1);
            $addAvail = (int)($_POST['rooms_available'] ?? 1);
            $addError = rm_validate_room_input($pdo, (string)$_POST['name'], $addTotal, $addAvail, null);
            if ($addError !== null) {
                throw new RuntimeException($addError);
            }
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            $room_image_url = normalizeRoomImagePath($_POST['image_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'rooms');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO rooms (name, description, short_description, price_per_night,
                    price_single_occupancy, price_double_occupancy, price_triple_occupancy,
                    child_price_multiplier, child_charge_mode, child_charge_amount, free_children, max_children,
                    single_occupancy_enabled, double_occupancy_enabled, triple_occupancy_enabled, children_allowed,
                    size_sqm, max_guests, rooms_available, total_rooms,
                    bed_type, amenities, image_url, is_featured, is_active, display_order, video_path, video_type, slug)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            // slug is required (NOT NULL): derive it from the name and keep it unique.
            $slugBase = substr(trim(preg_replace('/[^a-z0-9]+/', '-', strtolower((string)($_POST['name'] ?? 'room'))), '-'), 0, 90) ?: 'room'; // slug is VARCHAR(100)
            $maxGuests = max(1, (int)($_POST['max_guests'] ?? 2));
            $singleEnabled = $maxGuests >= 1 ? 1 : 0;
            $doubleEnabled = $maxGuests >= 2 ? 1 : 0;
            $tripleEnabled = ($maxGuests >= 3 && isset($_POST['triple_occupancy_enabled'])) ? 1 : 0;
            $childrenAllowed = isset($_POST['children_allowed']) ? 1 : 0;
            $cc = rh_child_rule_post($_POST, false);   // validated above
            $newRoomId = 0;
            rh_with_create_lock($pdo, 'room_type', function () use ($pdo, $stmt, $cc, $slugBase, &$newRoomId, $addTotal, $addAvail, $maxGuests, $singleEnabled, $doubleEnabled, $tripleEnabled, $childrenAllowed, $room_image_url, $videoPath, $videoType) {
            // re-check the name and pick a free slug under the lock
            $dupNow = rh_find_duplicate($pdo, 'rooms', 'name', (string)$_POST['name']);
            if ($dupNow) {
                throw new RuntimeException(rh_duplicate_message('room type', (string)$dupNow['value']));
            }
            $newSlug = $slugBase;
            $slugCheck = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE slug = ?');
            for ($si = 2; $slugCheck->execute([$newSlug]) && (int)$slugCheck->fetchColumn() > 0; $si++) {
                $newSlug = $slugBase . '-' . $si;
            }
            $stmt->execute([
                (string)$_POST['name'],
                $_POST['description'] ?? $_POST['short_description'] ?? '',
                $_POST['short_description'] ?? '',
                rm_num($_POST['price_per_night'] ?? 0),
                rm_num($_POST['price_single_occupancy'] ?? null, null),
                rm_num($_POST['price_double_occupancy'] ?? null, null),
                rm_num($_POST['price_triple_occupancy'] ?? null, null),
                $cc['percent'],
                $cc['mode'],
                $cc['amount'],
                $cc['free'],
                $cc['max'],
                $singleEnabled,
                $doubleEnabled,
                $tripleEnabled,
                $childrenAllowed,
                rm_num($_POST['size_sqm'] ?? 0),
                $maxGuests,
                $addAvail,
                $addTotal,
                $_POST['bed_type'] ?? 'Double',
                $_POST['amenities'] ?? '',
                $room_image_url,
                isset($_POST['is_featured']) ? 1 : 0,
                1,
                (int)($_POST['display_order'] ?? 0),
                $videoPath,
                $videoType,
                $newSlug
            ]);
            $newRoomId = (int)$pdo->lastInsertId();
            });
            if ($newRoomId > 0) {
                $mediaSyncStmt = $pdo->prepare("SELECT id, name, description, short_description, display_order, image_url, video_path, video_type FROM rooms WHERE id = ? LIMIT 1");
                $mediaSyncStmt->execute([$newRoomId]);
                $roomForMedia = $mediaSyncStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomForMedia) {
                    syncRoomManagedMedia($roomForMedia);
                }
            }

            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'New room added successfully!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => $message, 'room_id' => $newRoomId]);
                exit;
            }
        } elseif ($action === 'update_video') {
            $room_id = $_POST['room_id'] ?? $_POST['id'];
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'rooms');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            if ($videoPath) {
                $stmt = $pdo->prepare("UPDATE rooms SET video_path = ?, video_type = ? WHERE id = ?");
                $stmt->execute([$videoPath, $videoType, $room_id]);

                $mediaSyncStmt = $pdo->prepare("SELECT id, name, description, short_description, display_order, image_url, video_path, video_type FROM rooms WHERE id = ? LIMIT 1");
                $mediaSyncStmt->execute([$room_id]);
                $roomForMedia = $mediaSyncStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomForMedia) {
                    syncRoomManagedMedia($roomForMedia);
                }

                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room video updated successfully!';
            } elseif (isset($_POST['remove_video']) && $_POST['remove_video'] == '1') {
                $stmt = $pdo->prepare("UPDATE rooms SET video_path = NULL, video_type = NULL WHERE id = ?");
                $stmt->execute([$room_id]);

                $mediaSyncStmt = $pdo->prepare("SELECT id, name, description, short_description, display_order, image_url, video_path, video_type FROM rooms WHERE id = ? LIMIT 1");
                $mediaSyncStmt->execute([$room_id]);
                $roomForMedia = $mediaSyncStmt->fetch(PDO::FETCH_ASSOC);
                if ($roomForMedia) {
                    syncRoomManagedMedia($roomForMedia);
                }

                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                $message = 'Room video removed successfully!';
            } else {
                $error = 'No video URL or file provided.';
            }
        } elseif ($action === 'delete_room') {
            $room_id = (int)($_POST['id'] ?? 0);

            // One shared routine (also used by the API): blocks on individual rooms, joined-room combinations,
            // bookings and guest reviews; otherwise removes the room type with its gallery, blocked dates and files.
            $delRes = rh_delete_room_type($pdo, $room_id);
            if (!$delRes['success']) {
                $error = $delRes['error'];
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
                }
                throw new RuntimeException($error);
            }

            require_once __DIR__ . '/../config/cache.php';
            clearRoomCache();
            $message = 'Room deleted successfully!';
            if (is_ajax_request()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true]);
                exit;
            }
        } elseif ($action === 'update_order') {
            // Drag-and-drop reorder
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (is_array($order)) {
                $stmt = $pdo->prepare("UPDATE rooms SET display_order = ? WHERE id = ?");
                foreach ($order as $idx => $id) {
                    $stmt->execute([$idx, (int)$id]);
                }
                require_once __DIR__ . '/../config/cache.php';
                clearRoomCache();
                if (is_ajax_request()) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Order updated']);
                    exit;
                }
                $message = 'Room order updated!';
            }
        }
    } catch (Throwable $e) {
        $error = 'Error: ' . $e->getMessage();
        if (is_ajax_request()) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Fetch all rooms
try {
    $stmt = $pdo->query("SELECT * FROM rooms ORDER BY display_order ASC, name ASC");
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // A joined room type is sold as pairs: its inventory is the number of pairs (not the two
    // physical rooms behind each), so the card shows pairs in service / pairs.
    if (!empty($rooms) && bookingAvailabilityTableExists('room_combinations')) {
        $pairTotals = $pdo->query("SELECT combined_room_type_id, COUNT(*) FROM room_combinations WHERE is_active = 1 GROUP BY combined_room_type_id")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach ($rooms as &$pairRoomRow) {
            $pairTotal = (int)($pairTotals[(int)$pairRoomRow['id']] ?? 0);
            if ($pairTotal > 0) {
                $pairRoomRow['units_total'] = $pairTotal;
                $pairRoomRow['units_avail'] = rh_room_type_sellable_count((int)$pairRoomRow['id'], 0);
            }
        }
        unset($pairRoomRow);
    }

    if (!empty($rooms) && function_exists('applyManagedMediaOverrides')) {
        foreach ($rooms as &$managedRoomRow) {
            $managedRoomRow = applyManagedMediaOverrides($managedRoomRow, 'rooms', $managedRoomRow['id'] ?? '', ['image_url', 'video_path']);
        }
        unset($managedRoomRow);
    }
} catch (PDOException $e) {
    $error = 'Error fetching rooms: ' . $e->getMessage();
    $rooms = [];
}

$currency = htmlspecialchars(getSetting('currency_symbol'));
require_once __DIR__ . '/../includes/facebook-functions.php';
// Same rule as gym/menu/events: shown when posting is on, greyed with a hint when not connected.
$fb_rooms_posting_on = facebookShareVisible('rooms');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <title>Room Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/room-management.css?v=<?php echo @filemtime(__DIR__ . '/css/room-management.css'); ?>">
    <link rel="stylesheet" href="css/facebook-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/facebook-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header-row">
            <h2 class="page-title"><i class="fas fa-bed"></i> Manage Hotel Rooms</h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <span style="font-size:12px; color:#999;"><i class="fas fa-arrows-alt"></i> Drag the grip on a card to reorder (works by touch too)</span>
                <a class="btn-action" href="individual-rooms.php?combinations=1" style="background:#f5f0e8; color:#3e3930; padding:12px 24px; font-size:14px; border-radius:8px; text-decoration:none; border:1px solid #d3cbc0;" title="Pair adjoining physical rooms into one bookable joined-room unit">
                    <i class="fas fa-link"></i> Joined Rooms
                </a>
                <button class="btn-action" type="button" style="background:var(--gold,#7E684B); color:#fff; padding:12px 24px; font-size:14px; border-radius:8px;" onclick="openAddModal()">
                    <i class="fas fa-plus"></i> Add New Room
                </button>
            </div>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <?php if (!empty($rooms) && $fb_rooms_posting_on): ?>
            <div class="rh-share-bar">
                <i class="fab fa-facebook-f rh-share-bar__icon" aria-hidden="true"></i>
                <div class="rh-share-bar__text">
                    <div class="rh-share-bar__title">Share All Rooms on Facebook</div>
                    <div class="rh-share-bar__sub">Compose one post showcasing all your rooms — pick which to include, preview exactly how it'll look, then post.</div>
                </div>
                <button class="rh-share-bar__btn btn-facebook" type="button" onclick="openFbAllRoomsModal()"<?php echo facebookShareButtonAttrs('Share all rooms on Facebook'); ?>>
                    <i class="fab fa-facebook-f"></i> Compose &amp; Preview Post
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($rooms)): ?>
            <div class="room-cards-grid" id="roomsGrid">
                <?php foreach ($rooms as $room): ?>
                    <div class="room-card" data-id="<?php echo $room['id']; ?>">
                        <div class="drag-handle" tabindex="0" role="button" title="Drag to reorder (or press the up/down arrow keys)" aria-label="Move <?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?> in the order"><i class="fas fa-grip-vertical"></i></div>
                        <span class="order-badge">#<?php echo $room['display_order']; ?></span>

                        <?php if (!empty($room['image_url'])): ?>
                            <?php $imgSrc = preg_match('#^https?://#i', $room['image_url']) ? $room['image_url'] : '../' . $room['image_url']; ?>
                            <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                alt="<?php echo htmlspecialchars($room['name']); ?>"
                                class="room-card-image"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="no-image-placeholder" style="display:none;"><i class="fas fa-bed"></i><span>No Image</span></div>
                        <?php else: ?>
                            <div class="no-image-placeholder"><i class="fas fa-bed"></i><span>No Image</span></div>
                        <?php endif; ?>

                        <div class="room-card-body">
                            <div class="room-card-title">
                                <?php echo htmlspecialchars($room['name']); ?>
                            </div>
                            <div class="room-card-desc"><?php echo htmlspecialchars($room['short_description'] ?? $room['description'] ?? ''); ?></div>

                            <div class="room-card-details">
                                <div class="detail-item detail-item-price"><i class="fas fa-tag"></i> <?php echo $currency; ?> <?php echo number_format($room['price_per_night'], 0); ?>/night</div>
                                <div class="detail-item"><i class="fas fa-expand-arrows-alt"></i> <?php echo $room['size_sqm'] ?? 0; ?> sqm</div>
                                <div class="detail-item"><i class="fas fa-users"></i> Max <?php echo $room['max_guests'] ?? 2; ?> guests</div>
                                <div class="detail-item"><i class="fas fa-bed"></i> <?php echo htmlspecialchars($room['bed_type'] ?? 'N/A'); ?></div>
                                <div class="detail-item">
                                    <i class="fas fa-door-open"></i>
                                    <?php
                                    $avail = $room['units_avail'] ?? ($room['rooms_available'] ?? 0);
                                    $total = $room['units_total'] ?? ($room['total_rooms'] ?? 0);
                                    echo $avail . '/' . $total . (isset($room['units_total']) ? ' joined rooms avail' : ' avail');
                                    ?>
                                </div>
                            </div>

                            <?php if ($room['price_single_occupancy'] || $room['price_double_occupancy'] || $room['price_triple_occupancy']): ?>
                                <div style="font-size:11px; color:#888; margin-bottom:10px;">
                                    <?php if ($room['price_single_occupancy']): ?>Single: <?php echo $currency; ?> <?php echo number_format($room['price_single_occupancy'], 0); ?> <?php endif; ?>
                                <?php if ($room['price_double_occupancy']): ?>| Double: <?php echo $currency; ?> <?php echo number_format($room['price_double_occupancy'], 0); ?> <?php endif; ?>
                            <?php if ($room['price_triple_occupancy']): ?>| Triple: <?php echo $currency; ?> <?php echo number_format($room['price_triple_occupancy'], 0); ?> <?php endif; ?>
                        | Children: <?php echo htmlspecialchars(rh_child_rule_summary(rh_child_rule($room), $currency)); ?>
                                </div>
                            <?php endif; ?>

                            <div class="room-card-meta">
                                <?php if ($room['is_active']): ?>
                                    <span class="badge badge-active"><i class="fas fa-check"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive"><i class="fas fa-times"></i> Inactive</span>
                                <?php endif; ?>
                                <?php if ($room['is_featured']): ?>
                                    <span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                                <?php endif; ?>
                                <?php if (!empty($room['video_path'])): ?>
                                    <span class="badge badge-video"><i class="fas fa-video"></i> Video</span>
                                <?php endif; ?>
                            </div>

                            <div class="room-card-actions">
                                <!-- Toolbar: the two live toggles stay visible; media, share and delete sit in the shared More menu -->
                                <div class="rmc-toolbar">
                                    <button class="rmc-icon-btn <?php echo $room['is_active'] ? 'rmc-icon-btn--active-on' : 'rmc-icon-btn--active-off'; ?>" type="button"
                                        title="<?php echo $room['is_active'] ? 'ACTIVE — visible to guests. Click to deactivate.' : 'INACTIVE — hidden from guests. Click to make live.'; ?>"
                                        onclick="toggleActive(<?php echo $room['id']; ?>)">
                                        <i class="fas fa-power-off"></i>
                                    </button>
                                    <button class="rmc-icon-btn <?php echo !empty($room['is_featured']) ? 'rmc-icon-btn--featured-on' : ''; ?>" type="button"
                                        title="<?php echo !empty($room['is_featured']) ? 'FEATURED on homepage — click to unfeature.' : 'Not featured — click to show on homepage.'; ?>"
                                        onclick="toggleFeatured(<?php echo $room['id']; ?>)">
                                        <i class="fas fa-star"></i>
                                    </button>

                                    <span class="rmc-spacer"></span>

                                    <div class="actions-more">
                                        <button class="rmc-icon-btn actions-more-toggle" type="button" title="More actions" aria-label="More actions" aria-haspopup="true" onclick="toggleRoomCardMore(this, event)">
                                            <i class="fas fa-ellipsis-vertical"></i>
                                        </button>
                                        <div class="actions-more-menu">
                                            <button type="button"
                                                title="<?php echo !empty($room['image_url']) ? 'Change featured image' : 'Add a featured image'; ?>"
                                                onclick="closeRoomCardMenus(); openImageModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($room['image_url'] ?? '', ENT_QUOTES); ?>')">
                                                <i class="fas fa-image"></i> Featured image
                                            </button>
                                            <button type="button"
                                                title="Manage gallery photos (lightbox / photo tour)"
                                                onclick="closeRoomCardMenus(); openPicturesModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>')">
                                                <i class="fas fa-images"></i> Gallery photos
                                            </button>
                                            <button type="button"
                                                title="<?php echo !empty($room['video_path']) ? 'Replace or remove the room video' : 'Upload a video tour (MP4/WebM)'; ?>"
                                                onclick='closeRoomCardMenus(); openVideoModal(<?php echo $room["id"]; ?>, <?php echo htmlspecialchars(json_encode($room["name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["video_path"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["video_type"] ?? ""), ENT_QUOTES, "UTF-8"); ?>)'>
                                                <i class="fas fa-video"></i> Video tour
                                            </button>
                                            <?php if ($fb_rooms_posting_on): ?>
                                                <button type="button"<?php echo facebookShareButtonAttrs('Preview & post this room to your Facebook Page'); ?>
                                                    onclick='closeRoomCardMenus(); openFbShareModal(<?php echo (int)$room["id"]; ?>, <?php echo htmlspecialchars(json_encode($room["name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["image_url"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode(number_format((float)($room["price_per_night"] ?? 0))), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["slug"] ?? ""), ENT_QUOTES, "UTF-8"); ?>)'>
                                                    <i class="fab fa-facebook-f"></i> Share to Facebook
                                                </button>
                                            <?php endif; ?>
                                            <hr class="menu-divider">
                                            <button type="button" class="text-danger"
                                                title="Delete &quot;<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>&quot; permanently — cannot be undone"
                                                onclick="closeRoomCardMenus(); if(confirm('Delete &quot;<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>&quot; permanently?\n\nThis removes all images, videos and data. Cannot be undone.')) deleteRoom(<?php echo $room['id']; ?>)">
                                                <i class="fas fa-trash-alt"></i> Delete room
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Primary CTA -->
                                <button class="rmc-edit-btn" type="button"
                                    title="Edit room details — name, price, description, amenities, guests"
                                    onclick='openEditModal(<?php echo htmlspecialchars(json_encode($room), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-pen"></i> Edit Room
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div style="text-align:center; padding:60px; color:#999;">
                <i class="fas fa-bed" style="font-size:64px; margin-bottom:16px; color:#ddd; display:block;"></i>
                <p>No rooms found. Click "Add New Room" to get started.</p>
            </div>
        <?php endif; ?>

        <!-- Edit Room Modal -->
        <div class="modal-overlay" id="editModal">
            <div class="modal-content">
                <form method="POST" id="editForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" id="editAction" value="update">
                    <input type="hidden" name="id" id="editId">
                    <div class="modal-header">
                        <h3 id="editModalTitle"><i class="fas fa-edit"></i> Edit Room</h3>
                        <button class="modal-close" type="button" aria-label="Close" onclick="closeEditModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                            <div class="form-group">
                                <label>Room Name *</label>
                                <input type="text" name="name" id="editName" maxlength="100" required>
                            </div>
                            <div class="form-group">
                                <label>Short Description</label>
                                <textarea name="short_description" id="editShortDesc" rows="2" maxlength="255"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Full Description</label>
                                <textarea name="description" id="editDescription" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Featured Image URL / Path</label>
                                <input type="text" name="image_url" id="editImageUrl" placeholder="relative/path/to/image.jpg or https://cdn.example.com/room.jpg">
                                <small class="helper-text">Use relative image path or full external URL.</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-dollar-sign"></i> Pricing</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Price Per Night *</label>
                                    <input type="number" name="price_per_night" id="editPrice" step="0.01" min="0" required data-money data-currency="<?php echo $currency; ?>" placeholder="0.00">
                                    <small class="helper-text">What one night costs. Must be more than 0.</small>
                                </div>
                                <div class="form-group">
                                    <label>Single Occupancy Price</label>
                                    <input type="number" name="price_single_occupancy" id="editPriceSingle" step="0.01" min="0" data-money placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                    <small class="helper-text">Nightly price for 1 guest. Blank = use the price per night.</small>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Double Occupancy Price</label>
                                    <input type="number" name="price_double_occupancy" id="editPriceDouble" step="0.01" min="0" data-money placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                    <small class="helper-text">Nightly price for 2 guests. Blank = use the price per night.</small>
                                </div>
                                <div class="form-group">
                                    <label>Triple Occupancy Price</label>
                                    <input type="number" name="price_triple_occupancy" id="editPriceTriple" step="0.01" min="0" data-money placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                    <small class="helper-text">Nightly price for 3 guests. Blank = use the price per night.</small>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Children</label>
                                <?php rh_child_charge_fields('edit', false, $currency); ?>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" id="editTripleOccupancyEnabled" name="triple_occupancy_enabled" value="1">
                                        Enable Triple Occupancy (3 guests)
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" id="editChildrenAllowed" name="children_allowed" value="1">
                                        Allow Children for this Room Type
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-cog"></i> Room Details</div>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label>Size (sqm)</label>
                                    <input type="number" name="size_sqm" id="editSize" min="0" step="0.01">
                                </div>
                                <div class="form-group">
                                    <label>Max Guests</label>
                                    <input type="number" name="max_guests" id="editGuests" min="1" max="20" step="1" value="2">
                                </div>
                                <div class="form-group">
                                    <label>Bed Type</label>
                                    <input type="text" name="bed_type" id="editBedType" maxlength="50" placeholder="e.g. King Bed">
                                </div>
                            </div>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label>Rooms Available</label>
                                    <input type="number" name="rooms_available" id="editAvailable" min="0" step="1">
                                </div>
                                <div class="form-group">
                                    <label>Total Rooms</label>
                                    <input type="number" name="total_rooms" id="editTotal" min="1" step="1">
                                </div>
                                <div class="form-group">
                                    <label>Display Order</label>
                                    <input type="number" name="display_order" id="editOrder" min="0" step="1" value="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Amenities (comma-separated)</label>
                                <textarea name="amenities" id="editAmenities" rows="2" placeholder="WiFi, Air Conditioning, TV, Mini Bar"></textarea>
                            </div>
                        </div>

                        <div class="form-section" id="editPicturesSection">
                            <div class="form-section-title"><i class="fas fa-images"></i> Pictures</div>
                            <small class="helper-text" style="display:block; margin-bottom:8px;">The star picture is the main one guests see first. Pictures save as soon as you add, remove or reorder them (no need to press Save Changes). Most <?php echo htmlspecialchars(rh_format_bytes_short(RH_IMAGE_MAX_BYTES)); ?> each; JPG, PNG or WEBP.</small>
                            <div id="editPicturesGrid" class="rm-pic-grid"></div>
                            <div class="form-group" style="margin-top:10px;">
                                <label for="editPictureFiles">Add pictures</label>
                                <input type="file" id="editPictureFiles" accept="image/jpeg,image/png,image/webp" multiple>
                                <small class="helper-text">Choose one or more pictures, or drop them anywhere in this Pictures box; they upload straight away. Drag a picture onto another to change the order.</small>
                            </div>
                            <div id="editPicturesMsg" class="rm-pic-msg" aria-live="polite"></div>
                        </div>

                        <div class="form-section" style="border-bottom:none;">
                            <div class="form-section-title"><i class="fas fa-toggle-on"></i> Status</div>
                            <div class="checkbox-row">
                                <label>
                                    <input type="checkbox" name="is_active" id="editIsActive" value="1"> Active
                                </label>
                                <label>
                                    <input type="checkbox" name="is_featured" id="editIsFeatured" value="1"> Featured
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions modal-footer">
                        <div id="editRoomFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                        <button type="button" onclick="closeEditModal()">Close</button>
                        <button type="submit" id="editRoomSaveBtn">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Room Modal -->
        <div class="modal-overlay" id="addModal">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="addForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="add_room">
                    <div class="modal-header">
                        <h3><i class="fas fa-plus-circle"></i> Add New Room</h3>
                        <button class="modal-close" type="button" aria-label="Close" onclick="closeAddModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                            <div class="form-group">
                                <label>Room Name *</label>
                                <input type="text" name="name" id="addName" maxlength="100" required>
                            </div>
                            <div class="form-group">
                                <label>Short Description</label>
                                <textarea name="short_description" id="addShortDesc" rows="2" maxlength="255"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Full Description</label>
                                <textarea name="description" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Featured Image URL / Path</label>
                                <input type="text" name="image_url" placeholder="relative/path/to/image.jpg or https://cdn.example.com/room.jpg">
                                <small class="helper-text">Optional. Or pick pictures in the Pictures section below; the first one becomes the main picture.</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-dollar-sign"></i> Pricing</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Price Per Night *</label>
                                    <input type="number" name="price_per_night" id="addPrice" step="0.01" min="0" required data-money data-currency="<?php echo $currency; ?>" placeholder="0.00">
                                    <small class="helper-text">What one night costs. Must be more than 0.</small>
                                </div>
                                <div class="form-group">
                                    <label>Single Occupancy Price</label>
                                    <input type="number" name="price_single_occupancy" id="addPriceSingle" step="0.01" min="0" data-money placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                    <small class="helper-text">Nightly price for 1 guest. Blank = use the price per night.</small>
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Double Occupancy Price</label>
                                    <input type="number" name="price_double_occupancy" id="addPriceDouble" step="0.01" min="0" data-money placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                    <small class="helper-text">Nightly price for 2 guests. Blank = use the price per night.</small>
                                </div>
                                <div class="form-group">
                                    <label>Triple Occupancy Price</label>
                                    <input type="number" name="price_triple_occupancy" id="addPriceTriple" step="0.01" min="0" data-money placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                    <small class="helper-text">Nightly price for 3 guests. Blank = use the price per night.</small>
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Children</label>
                                <?php rh_child_charge_fields('add', false, $currency); ?>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="triple_occupancy_enabled" value="1">
                                        Enable Triple Occupancy (3 guests)
                                    </label>
                                </div>
                                <div class="form-group">
                                    <label>
                                        <input type="checkbox" name="children_allowed" value="1" checked>
                                        Allow Children for this Room Type
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-cog"></i> Room Details</div>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label>Size (sqm)</label>
                                    <input type="number" name="size_sqm" id="addSize" min="0" step="0.01">
                                </div>
                                <div class="form-group">
                                    <label>Max Guests</label>
                                    <input type="number" name="max_guests" id="addGuests" min="1" max="20" step="1" value="2">
                                </div>
                                <div class="form-group">
                                    <label>Bed Type</label>
                                    <input type="text" name="bed_type" id="addBedType" maxlength="50" value="Double">
                                </div>
                            </div>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label>Rooms Available</label>
                                    <input type="number" name="rooms_available" id="addAvailable" min="0" step="1" value="1">
                                </div>
                                <div class="form-group">
                                    <label>Total Rooms</label>
                                    <input type="number" name="total_rooms" id="addTotal" min="1" step="1" value="1">
                                </div>
                                <div class="form-group">
                                    <label>Display Order</label>
                                    <input type="number" name="display_order" id="addOrder" min="0" step="1" value="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Amenities (comma-separated)</label>
                                <textarea name="amenities" rows="2" placeholder="WiFi, Air Conditioning, TV, Mini Bar"></textarea>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-images"></i> Pictures (Optional)</div>
                            <div class="form-group">
                                <label for="addPictureFiles">Choose pictures</label>
                                <input type="file" id="addPictureFiles" accept="image/jpeg,image/png,image/webp" multiple>
                                <small class="helper-text">Choose them, or drop picture files in this box. They upload right after the room is created. The first one becomes the main picture. Most <?php echo htmlspecialchars(rh_format_bytes_short(RH_IMAGE_MAX_BYTES)); ?> each; JPG, PNG or WEBP.</small>
                            </div>
                            <div id="addPicturesList" class="rm-pic-msg"></div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-video"></i> Video (Optional)</div>
                            <div class="form-group">
                                <label>Video URL</label>
                                <input type="url" name="video_url" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                                <small style="color:#888;">YouTube, Vimeo, Dailymotion, or direct video URL</small>
                            </div>
                        </div>

                        <div class="form-section" style="border-bottom:none;">
                            <div class="form-section-title"><i class="fas fa-toggle-on"></i> Status</div>
                            <div class="checkbox-row">
                                <label>
                                    <input type="checkbox" name="is_featured" value="1"> Featured Room
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions modal-footer">
                        <div id="addRoomFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                        <button type="button" onclick="closeAddModal()">Close</button>
                        <button type="submit" id="addRoomSaveBtn">
                            <i class="fas fa-plus"></i> Add Room
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Image Upload Modal -->
        <div class="modal-overlay" id="imageModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="imageModalTitle"><i class="fas fa-image"></i> Room Image</h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closeImageModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="currentImageContainer" style="display:none; margin-bottom:20px;">
                        <h4 style="margin-bottom:12px; color:#666;">Current Image</h4>
                        <img id="currentImage" class="current-image" alt="Current room image">
                    </div>
                    <h4 style="margin:0 0 12px; color:#666;">Upload New Image</h4>
                    <form method="POST" enctype="multipart/form-data" id="imageUploadForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                        <input type="hidden" name="action" value="upload_image">
                        <input type="hidden" name="room_id" id="uploadRoomId">

                        <div class="upload-area" onclick="document.getElementById('roomImageInput').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p style="margin:8px 0; color:#666;">Click or drag an image here</p>
                            <small style="color:#999;">JPG, PNG, WEBP (most <?php echo htmlspecialchars(rh_format_bytes_short(RH_IMAGE_MAX_BYTES)); ?>)</small>
                        </div>
                        <div class="form-group" style="margin-top:12px;">
                            <input type="file" name="room_image" id="roomImageInput" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                    </form>

                    <div class="image-source-divider"><span>OR</span></div>

                    <form method="POST" id="imageUrlForm" class="image-source-panel">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                        <input type="hidden" name="action" value="update_image_url">
                        <input type="hidden" name="room_id" id="imageUrlRoomId">
                        <label class="image-source-label" for="imageUrlInput">Set Image URL / Path</label>
                        <input type="text" name="image_url_input" id="imageUrlInput" class="form-control" placeholder="relative/path/to/image.jpg or https://cdn.example.com/room_hero.jpg">
                        <button type="submit" class="btn-action btn-image-source-save"><i class="fas fa-link"></i> Save URL/Path</button>
                    </form>
                </div>
                <div class="form-actions modal-footer">
                    <button type="button" onclick="closeImageModal()">Close</button>
                </div>
            </div>
        </div>

        <!-- Video Modal -->
        <div class="modal-overlay" id="videoModal">
            <div class="modal-content">
                <form method="POST" enctype="multipart/form-data" id="videoForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                    <input type="hidden" name="action" value="update_video">
                    <input type="hidden" name="room_id" id="videoRoomId">
                    <div class="modal-header">
                        <h3 id="videoModalTitle"><i class="fas fa-video"></i> Room Video</h3>
                        <button class="modal-close" type="button" aria-label="Close" onclick="closeVideoModal()">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div id="currentVideoInfo" style="display:none; margin-bottom:16px; background:#f0f7ff; padding:12px; border-radius:6px;">
                            <i class="fas fa-video" style="color:var(--gold);"></i> <span id="currentVideoText"></span>
                            <label style="display:inline-flex; align-items:center; gap:4px; margin-left:12px; cursor:pointer;">
                                <input type="checkbox" name="remove_video" value="1"> Remove video
                            </label>
                        </div>
                        <div class="form-group">
                            <label>Video URL</label>
                            <input type="url" name="video_url" id="videoUrlInput" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                            <small style="color:#888;">YouTube, Vimeo, Dailymotion, or direct video URL</small>
                        </div>
                        <div style="text-align:center; color:#999; font-size:12px; margin:12px 0;">— OR upload a video file —</div>
                        <div class="form-group">
                            <input type="file" name="video" accept="video/*">
                        </div>
                    </div>
                    <div class="form-actions modal-footer">
                        <button type="button" onclick="closeVideoModal()">Cancel</button>
                        <button type="submit">
                            <i class="fas fa-save"></i> Save Video
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Pictures Modal -->
        <div class="modal-overlay" id="picturesModal">
            <div class="modal-content" style="max-width:900px;">
                <div class="modal-header">
                    <h3 id="picturesModalTitle"><i class="fas fa-images"></i> Room Pictures</h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closePicturesModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data" id="pictureUploadForm" style="margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #eee;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>">
                        <input type="hidden" name="action" value="upload_picture">
                        <input type="hidden" name="room_id" id="pictureRoomId">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Image File *</label>
                                <input type="file" name="image" id="pictureFileInput" accept="image/jpeg,image/png,image/webp" required>
                            </div>
                            <div class="form-group">
                                <label>Title</label>
                                <input type="text" name="picture_title" placeholder="Picture title">
                            </div>
                        </div>
                        <div style="display:flex; gap:12px; align-items:center; flex-wrap:wrap;">
                            <div class="form-group" style="flex:1; margin-bottom:0;">
                                <input type="text" name="picture_description" placeholder="Brief description">
                            </div>
                            <label style="display:flex; align-items:center; gap:6px; cursor:pointer; font-size:13px; white-space:nowrap;">
                                <input type="checkbox" name="set_featured"> Featured
                            </label>
                            <button type="submit" class="btn-action" style="background:var(--gold,#7E684B); color:#fff; padding:8px 16px;">
                                <i class="fas fa-upload"></i> Upload
                            </button>
                        </div>
                    </form>
                    <div id="picturesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:12px;">
                        <div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;">
                            <i class="fas fa-images"></i> Select a room to view pictures
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script src="js/admin-form-guard.js?v=<?php echo @filemtime(__DIR__ . '/js/admin-form-guard.js'); ?>"></script>
        <script>
            // Every page POST carries the admin token. This sits in the page body (not <head>) because the
            // admin in-place navigation drops head scripts, which made every save/upload here fail with
            // "Security token invalid" after the screen was opened from the menu.
            (function() {
                var _t = <?php echo json_encode($csrf_token); ?>;
                if (window.__rmCsrfToken === _t) return;
                window.__rmCsrfToken = _t;
                var _f = window.fetch;
                window.fetch = function(u, o) {
                    if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                    return _f.apply(this, arguments);
                };
            })();
            // ===== EDIT MODAL =====
            // ===== MORE-ACTIONS MENU (shared placement from admin-components.js) =====
            function closeRoomCardMenus() {
                document.querySelectorAll('.room-card-actions .actions-more.open').forEach(function (el) {
                    el.classList.remove('open');
                    var m = el.querySelector('.actions-more-menu');
                    if (m) m.removeAttribute('style');
                });
            }

            function toggleRoomCardMore(btn, e) {
                if (e) e.stopPropagation();
                var wrap = btn.closest('.actions-more');
                var menu = wrap ? wrap.querySelector('.actions-more-menu') : null;
                if (!menu) return;
                var wasOpen = wrap.classList.contains('open');
                closeRoomCardMenus();
                if (wasOpen) return;
                wrap.classList.add('open');
                if (window.rhPlaceActionMenu) window.rhPlaceActionMenu(btn, menu);
            }
            document.addEventListener('click', function (e) {
                if (!e.target.closest('.actions-more')) closeRoomCardMenus();
            });
            document.addEventListener('scroll', function (e) {
                if (!(e.target && e.target.closest && e.target.closest('.actions-more-menu'))) closeRoomCardMenus();
            }, true);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeRoomCardMenus();
            });

            function openEditModal(room) {
                document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit: ' + escapeHtml(room.name);
                document.getElementById('editAction').value = 'update';
                document.getElementById('editId').value = room.id;
                document.getElementById('editName').value = room.name || '';
                document.getElementById('editShortDesc').value = room.short_description || '';
                document.getElementById('editDescription').value = room.description || '';
                document.getElementById('editPrice').value = rmMoney(room.price_per_night, true);
                document.getElementById('editPriceSingle').value = rmMoney(room.price_single_occupancy, false);
                document.getElementById('editPriceDouble').value = rmMoney(room.price_double_occupancy, false);
                document.getElementById('editPriceTriple').value = rmMoney(room.price_triple_occupancy, false);
                // Child charge: percent (default) or fixed amount, free children, most children per room.
                document.getElementById('editChildMode').value = room.child_charge_mode === 'fixed' ? 'fixed' : 'percent';
                document.getElementById('editChildPct').value = (room.child_price_multiplier === null || room.child_price_multiplier === undefined || room.child_price_multiplier === '') ? '50.00' : rmMoney(room.child_price_multiplier, true);
                document.getElementById('editChildAmt').value = (room.child_charge_amount === null || room.child_charge_amount === undefined || room.child_charge_amount === '') ? '' : rmMoney(room.child_charge_amount, true);
                document.getElementById('editChildFree').value = room.free_children || 0;
                document.getElementById('editChildMax').value = (room.max_children === null || room.max_children === undefined) ? '' : room.max_children;
                document.getElementById('editChildMode').dispatchEvent(new Event('change'));
                document.getElementById('editTripleOccupancyEnabled').checked = String(room.triple_occupancy_enabled || '0') === '1';
                document.getElementById('editChildrenAllowed').checked = String(room.children_allowed || '0') === '1';
                document.getElementById('editSize').value = room.size_sqm || '';
                document.getElementById('editGuests').value = room.max_guests || 2;
                document.getElementById('editBedType').value = room.bed_type || '';
                document.getElementById('editAvailable').value = room.rooms_available || 0;
                document.getElementById('editTotal').value = room.total_rooms || 0;
                document.getElementById('editOrder').value = room.display_order || 0;
                document.getElementById('editAmenities').value = room.amenities || '';
                document.getElementById('editImageUrl').value = room.image_url || '';
                document.getElementById('editIsActive').checked = room.is_active == 1;
                document.getElementById('editIsFeatured').checked = room.is_featured == 1;
                if (window.RHFormGuard) RHFormGuard.clearErrors(document.getElementById('editForm'));
                document.getElementById('editModal').style.display = 'flex';
                rmLoadPictures();
            }

            // A stored number shown with 2 decimals (45000 -> 45000.00). Blank when the value is empty (and, for optional prices, 0).
            function rmMoney(v, keepZero) {
                if (v === null || v === undefined || v === '') return '';
                var n = Number(v);
                if (isNaN(n)) return '';
                if (n === 0 && !keepZero) return '';
                return n.toFixed(2);
            }

            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
                document.getElementById('editRoomFeedback').className = 'admin-modal-feedback';
                document.getElementById('editRoomFeedback').innerHTML = '';
            }

            // ===== ADD MODAL =====
            function openAddModal() {
                if (window.RHFormGuard) RHFormGuard.clearErrors(document.getElementById('addForm'));
                var _af = document.getElementById('addForm');
                _af.reset();
                document.getElementById('addChildMode').dispatchEvent(new Event('change'));
                document.getElementById('addPicturesList').textContent = '';
                document.getElementById('addPicturesList').className = 'rm-pic-msg';
                document.getElementById('addModal').style.display = 'flex';
            }

            function closeAddModal() {
                document.getElementById('addModal').style.display = 'none';
                document.getElementById('addRoomFeedback').className = 'admin-modal-feedback';
                document.getElementById('addRoomFeedback').innerHTML = '';
            }

            // ===== IMAGE MODAL =====
            function openImageModal(roomId, roomName, currentImageUrl) {
                document.getElementById('imageModalTitle').innerHTML = '<i class="fas fa-image"></i> ' + escapeHtml(roomName);
                document.getElementById('uploadRoomId').value = roomId;
                document.getElementById('imageUrlRoomId').value = roomId;
                document.getElementById('imageUrlInput').value = currentImageUrl || '';

                if (currentImageUrl) {
                    const src = /^https?:\/\//i.test(currentImageUrl) ? currentImageUrl : ('../' + currentImageUrl);
                    document.getElementById('currentImage').src = src;
                    document.getElementById('currentImageContainer').style.display = 'block';
                } else {
                    document.getElementById('currentImageContainer').style.display = 'none';
                }
                document.getElementById('imageModal').style.display = 'flex';
            }

            function closeImageModal() {
                document.getElementById('imageModal').style.display = 'none';
                document.getElementById('imageUploadForm').reset();
            }

            // Auto-upload on file select
            document.getElementById('roomImageInput').addEventListener('change', function() {
                const form = document.getElementById('imageUploadForm');
                const formData = new FormData(form);
                fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(r => r.json().catch(() => null))
                    .then(data => {
                        if (data && data.success) {
                            const src = /^https?:\/\//i.test(data.image_url) ? data.image_url : ('../' + data.image_url);
                            document.getElementById('currentImage').src = src + '?t=' + Date.now();
                            document.getElementById('currentImageContainer').style.display = 'block';
                            document.getElementById('imageUrlInput').value = data.image_url || '';
                            // Update card image
                            const card = document.querySelector('.room-card[data-id="' + document.getElementById('uploadRoomId').value + '"]');
                            if (card) {
                                let img = card.querySelector('.room-card-image');
                                if (img) {
                                    img.src = src + '?t=' + Date.now();
                                    img.style.display = '';
                                    const placeholder = card.querySelector('.no-image-placeholder');
                                    if (placeholder) placeholder.style.display = 'none';
                                }
                            }
                            form.reset();
                        } else {
                            alert(data && data.message ? data.message : 'Error uploading image');
                        }
                    })
                    .catch(() => alert('Error uploading image'));
            });

            document.getElementById('imageUrlForm').addEventListener('submit', function(e) {
                e.preventDefault();
                const form = this;
                const formData = new FormData(form);

                fetch(window.location.href, {
                        method: 'POST',
                        body: formData,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(r => r.json().catch(() => null))
                    .then(data => {
                        if (data && data.success) {
                            const src = /^https?:\/\//i.test(data.image_url) ? data.image_url : ('../' + data.image_url);
                            document.getElementById('currentImage').src = src + '?t=' + Date.now();
                            document.getElementById('currentImageContainer').style.display = 'block';
                            document.getElementById('imageUrlInput').value = data.image_url;

                            const card = document.querySelector('.room-card[data-id="' + document.getElementById('imageUrlRoomId').value + '"]');
                            if (card) {
                                let img = card.querySelector('.room-card-image');
                                if (img) {
                                    img.src = src + '?t=' + Date.now();
                                    img.style.display = '';
                                    const placeholder = card.querySelector('.no-image-placeholder');
                                    if (placeholder) placeholder.style.display = 'none';
                                }
                            }
                        } else {
                            alert(data && data.message ? data.message : 'Error saving image URL/path');
                        }
                    })
                    .catch(() => alert('Error saving image URL/path'));
            });

            // ===== VIDEO MODAL =====
            function openVideoModal(roomId, roomName, videoPath, videoType) {
                document.getElementById('videoModalTitle').innerHTML = '<i class="fas fa-video"></i> ' + escapeHtml(roomName);
                document.getElementById('videoRoomId').value = roomId;
                document.getElementById('videoUrlInput').value = '';

                if (videoPath) {
                    const text = videoPath.substring(0, 60) + (videoPath.length > 60 ? '...' : '') + ' (' + (videoType || 'unknown') + ')';
                    document.getElementById('currentVideoText').textContent = text;
                    document.getElementById('currentVideoInfo').style.display = 'block';
                    if (/^https?:\/\//i.test(videoPath)) {
                        document.getElementById('videoUrlInput').value = videoPath;
                    }
                } else {
                    document.getElementById('currentVideoInfo').style.display = 'none';
                }
                document.getElementById('videoModal').style.display = 'flex';
            }

            function closeVideoModal() {
                document.getElementById('videoModal').style.display = 'none';
                document.getElementById('videoForm').reset();
            }

            // ===== PICTURES MODAL =====
            function openPicturesModal(roomId, roomName) {
                document.getElementById('picturesModalTitle').innerHTML = '<i class="fas fa-images"></i> ' + escapeHtml(roomName) + ' - Pictures';
                document.getElementById('pictureRoomId').value = roomId;
                document.getElementById('picturesModal').style.display = 'flex';
                loadRoomPictures(roomId);
            }

            function closePicturesModal() {
                document.getElementById('picturesModal').style.display = 'none';
                document.getElementById('pictureUploadForm').reset();
            }

            function loadRoomPictures(roomId) {
                const grid = document.getElementById('picturesGrid');
                grid.innerHTML = '<div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;"><i class="fas fa-spinner fa-spin"></i> Loading...</div>';

                fetch('api/room-pictures.php?room_id=' + roomId)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success && data.data && data.data.gallery) {
                            renderPictures(data.data.gallery);
                        } else {
                            grid.innerHTML = '<div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;"><i class="fas fa-images"></i> No pictures yet</div>';
                        }
                    })
                    .catch(() => {
                        grid.innerHTML = '<div style="text-align:center; padding:40px; color:#dc3545; grid-column:1/-1;">Error loading pictures</div>';
                    });
            }

            function renderPictures(pictures) {
                const grid = document.getElementById('picturesGrid');
                if (!pictures || pictures.length === 0) {
                    grid.innerHTML = '<div style="text-align:center; padding:40px; color:#999; grid-column:1/-1;"><i class="fas fa-images"></i> No pictures yet</div>';
                    return;
                }
                grid.innerHTML = pictures.map(function(p) {
                    var src = /^https?:\/\//i.test(p.image_url) ? p.image_url : ('../' + p.image_url);
                    var featured = p.is_featured ? '<span style="position:absolute; top:4px; left:4px; background:var(--gold); color:white; padding:2px 6px; border-radius:4px; font-size:10px;"><i class="fas fa-star"></i></span>' : '';
                    return '<div style="position:relative; border-radius:8px; overflow:hidden; border:1px solid #eee;">' +
                        '<img src="' + src + '" alt="' + escapeHtml(p.title || '') + '" style="width:100%; height:120px; object-fit:cover;">' +
                        featured +
                        '<div style="padding:6px; font-size:11px;">' +
                        '<div style="font-weight:600;">' + escapeHtml(p.title || 'Untitled') + '</div>' +
                        '<div style="display:flex; gap:4px; margin-top:4px;">' +
                        (!p.is_featured ? '<button class="btn-action" style="background:var(--gold); color:white; padding:2px 6px; font-size:10px;" onclick="setFeaturedPicture(' + p.id + ')"><i class="fas fa-star"></i></button>' : '') +
                        '<button class="btn-action btn-delete" style="padding:2px 6px; font-size:10px;" onclick="deletePicture(' + p.id + ')"><i class="fas fa-trash"></i></button>' +
                        '</div></div></div>';
                }).join('');
            }

            function setFeaturedPicture(pictureId) {
                if (!confirm('Set as featured image?')) return;
                var roomId = document.getElementById('pictureRoomId').value;
                fetch('api/room-pictures.php?action=set_featured', {
                        method: 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': <?php echo json_encode($csrf_token); ?>
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({
                            room_id: roomId,
                            picture_id: pictureId
                        })
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            loadRoomPictures(roomId);
                            setTimeout(function() {
                                window.location.reload();
                            }, 1000);
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(function() {
                        alert('Error');
                    });
            }

            function deletePicture(pictureId) {
                if (!confirm('Delete this picture?')) return;
                var roomId = document.getElementById('pictureRoomId').value;
                fetch('api/room-pictures.php?picture_id=' + pictureId, {
                        method: 'DELETE',
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': <?php echo json_encode($csrf_token); ?>
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) loadRoomPictures(roomId);
                        else alert('Error: ' + data.message);
                    })
                    .catch(function() {
                        alert('Error');
                    });
            }

            // Handle picture upload
            document.getElementById('pictureUploadForm').addEventListener('submit', function(e) {
                e.preventDefault();
                var self = this;
                fetch('api/room-pictures.php', {
                        method: 'POST',
                        body: new FormData(self),
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-Token': <?php echo json_encode($csrf_token); ?>
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) {
                            self.reset();
                            loadRoomPictures(document.getElementById('pictureRoomId').value);
                        } else {
                            alert(data.message || 'Error');
                        }
                    })
                    .catch(function() {
                        alert('Error uploading');
                    });
            });

            // ===== PICTURES INSIDE THE ADD / EDIT MODAL (uses api/room-pictures.php) =====
            var RM_TOKEN = <?php echo json_encode($csrf_token); ?>;
            var RM_MAX_BYTES = <?php echo (int)RH_IMAGE_MAX_BYTES; ?>;
            var RM_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
            var rmPics = [];
            var rmFeaturedUrl = '';

            function rmPicSrc(u) { return /^https?:\/\//i.test(u) ? u : ('../' + u); }
            function rmPicMsg(text, isError) {
                var el = document.getElementById('editPicturesMsg');
                el.textContent = text || '';
                el.className = 'rm-pic-msg' + (isError ? ' is-error' : '');
            }
            function rmPicRequest(method, url, body) {
                var opts = { method: method, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': RM_TOKEN } };
                if (body instanceof FormData) { opts.body = body; }
                else if (body) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
                return fetch(url, opts).then(function(r) {
                    return r.json().catch(function() { return { success: false, message: 'The server sent an unexpected answer (HTTP ' + r.status + ').' }; });
                });
            }
            function rmPicConfirm(msg) {
                if (window.AdminConfirm && window.AdminConfirm.request) {
                    return window.AdminConfirm.request({ title: 'Please confirm', message: msg, confirmText: 'Yes', cancelText: 'Cancel', tone: 'warning', icon: 'fa-trash' });
                }
                return Promise.resolve(confirm(msg));
            }
            function rmSetMainUrl(url) {
                rmFeaturedUrl = url || '';
                document.getElementById('editImageUrl').value = rmFeaturedUrl; // Save Changes must not put the old one back
            }

            function rmLoadPictures() {
                var roomId = document.getElementById('editId').value;
                var grid = document.getElementById('editPicturesGrid');
                rmPicMsg('');
                grid.innerHTML = '<div class="rm-pic-empty"><i class="fas fa-spinner fa-spin"></i> Loading pictures...</div>';
                fetch('api/room-pictures.php?room_id=' + encodeURIComponent(roomId), { credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (data && data.success && data.data) {
                            rmPics = data.data.gallery || [];
                            rmFeaturedUrl = (data.data.room && data.data.room.image_url) || '';
                            rmRenderPictures();
                        } else {
                            grid.innerHTML = '<div class="rm-pic-empty">' + escapeHtml((data && data.message) || 'Pictures could not be loaded.') + '</div>';
                        }
                    })
                    .catch(function() { grid.innerHTML = '<div class="rm-pic-empty">Pictures could not be loaded.</div>'; });
            }

            function rmRenderPictures() {
                var grid = document.getElementById('editPicturesGrid');
                var html = '';
                var inGallery = rmPics.some(function(p) { return p.image_url === rmFeaturedUrl; });
                if (rmFeaturedUrl && !inGallery) {
                    html += '<div class="rm-pic" data-main-only="1"><img src="' + escapeHtml(rmPicSrc(rmFeaturedUrl)) + '" alt="Main picture"><span class="rm-pic__main"><i class="fas fa-star"></i> Main</span>' +
                        '<div class="rm-pic__note">Main picture (not in the gallery).</div></div>';
                }
                rmPics.forEach(function(p, i) {
                    var isMain = p.image_url === rmFeaturedUrl;
                    html += '<div class="rm-pic" draggable="true" data-id="' + Number(p.id) + '" data-i="' + i + '">' +
                        '<img draggable="false" src="' + escapeHtml(rmPicSrc(p.image_url)) + '" alt="' + escapeHtml(p.title || 'Room picture') + '">' +
                        (isMain ? '<span class="rm-pic__main"><i class="fas fa-star"></i> Main</span>' : '') +
                        '<div class="rm-pic__bar">' +
                        '<button type="button" data-act="main" title="Make this the main picture"' + (isMain ? ' disabled' : '') + '><i class="fas fa-star"></i></button>' +
                        '<button type="button" data-act="left" title="Move earlier"' + (i === 0 ? ' disabled' : '') + '><i class="fas fa-arrow-left"></i></button>' +
                        '<button type="button" data-act="right" title="Move later"' + (i === rmPics.length - 1 ? ' disabled' : '') + '><i class="fas fa-arrow-right"></i></button>' +
                        '<button type="button" class="rm-pic__del" data-act="del" title="Remove this picture"><i class="fas fa-trash"></i></button>' +
                        '</div></div>';
                });
                grid.innerHTML = html || '<div class="rm-pic-empty">No pictures yet. Use Add pictures below.</div>';
            }

            document.getElementById('editPicturesGrid').addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-act]');
                if (!btn || btn.disabled) return;
                var card = btn.closest('.rm-pic');
                var id = Number(card.dataset.id), idx = Number(card.dataset.i), roomId = document.getElementById('editId').value;
                var act = btn.dataset.act;
                if (act === 'main') {
                    btn.disabled = true;
                    rmPicRequest('PUT', 'api/room-pictures.php?action=set_featured', { room_id: roomId, picture_id: id }).then(function(res) {
                        if (res.success) { rmSetMainUrl(rmPics[idx].image_url); rmRenderPictures(); refreshRoomsGrid(); rmPicMsg('Main picture changed.'); }
                        else { rmPicMsg(res.message || 'Could not change the main picture.', true); btn.disabled = false; }
                    });
                } else if (act === 'del') {
                    var wasMain = rmPics[idx].image_url === rmFeaturedUrl;
                    rmPicConfirm('Remove this picture?' + (wasMain ? ' It is the main picture, so the room will have no main picture until you star another one.' : '')).then(function(ok) {
                        if (!ok) return;
                        rmPicRequest('DELETE', 'api/room-pictures.php?picture_id=' + id).then(function(res) {
                            if (res.success) { if (wasMain) rmSetMainUrl(''); rmPicMsg('Picture removed.'); rmLoadPictures(); refreshRoomsGrid(); }
                            else rmPicMsg(res.message || 'Could not remove the picture.', true);
                        });
                    });
                } else if (act === 'left' || act === 'right') {
                    rmMovePicture(idx, act === 'left' ? idx - 1 : idx + 1);
                }
            });

            /** Move the picture at index `from` to index `to`, show it now and save the new numbers. */
            function rmMovePicture(from, to) {
                if (from === to || from < 0 || to < 0 || from >= rmPics.length || to >= rmPics.length) return;
                var order = rmPics.slice();
                var moved = order.splice(from, 1)[0];
                order.splice(to, 0, moved);
                // Renumber 1..n, sending only the pictures whose number changes.
                var jobs = [];
                order.forEach(function(p, k) { if (Number(p.display_order) !== k + 1) jobs.push({ picture_id: p.id, display_order: k + 1 }); });
                rmPics = order.map(function(p, k) { p.display_order = k + 1; return p; });
                rmRenderPictures();
                jobs.reduce(function(chain, job) {
                    return chain.then(function() { return rmPicRequest('PUT', 'api/room-pictures.php', job); });
                }, Promise.resolve()).then(function() { rmPicMsg('Order saved.'); }).catch(function() { rmPicMsg('Could not save the new order.', true); });
            }

            /** Upload files one after another to a room. Resolves {done, failed:[messages], firstUrl}. */
            function rmUploadFiles(roomId, files, onProgress) {
                var out = { done: 0, failed: [], firstUrl: '' };
                var list = Array.prototype.slice.call(files);
                return list.reduce(function(chain, file, i) {
                    return chain.then(function() {
                        if (onProgress) onProgress(i + 1, list.length);
                        var fd = new FormData();
                        fd.append('room_id', roomId);
                        fd.append('image', file);
                        fd.append('csrf_token', RM_TOKEN);
                        return rmPicRequest('POST', 'api/room-pictures.php', fd).then(function(res) {
                            if (res.success) { out.done++; if (!out.firstUrl && res.data) out.firstUrl = res.data.image_url; }
                            else out.failed.push(file.name + ': ' + (res.message || 'upload failed'));
                        }).catch(function() { out.failed.push(file.name + ': network error'); });
                    });
                }, Promise.resolve()).then(function() { return out; });
            }

            function rmCheckFiles(input) {
                for (var i = 0; i < input.files.length; i++) {
                    var f = input.files[i];
                    if (RM_TYPES.indexOf(f.type) < 0) return f.name + ': only JPG, PNG or WEBP pictures are allowed.';
                    if (f.size > RM_MAX_BYTES) return f.name + ' is too large (' + (f.size / 1048576).toFixed(1) + ' MB). The most allowed is ' + (RM_MAX_BYTES / 1048576).toFixed(0) + ' MB.';
                }
                return '';
            }

            document.getElementById('editPictureFiles').addEventListener('change', function() {
                rmEditUpload(this.files, this);
            });

            /** Upload chosen or dropped files to the room open in the edit window (input = the file box to clear, or null). */
            function rmEditUpload(fileList, input) {
                var files = Array.prototype.slice.call(fileList);
                if (!files.length) return;
                var problem = rmCheckFiles({ files: files });
                if (problem) { rmPicMsg(problem, true); if (input) input.value = ''; return; }
                var roomId = document.getElementById('editId').value;
                if (!roomId) return;
                var hadMain = rmFeaturedUrl !== '';
                rmUploadFiles(roomId, files, function(n, total) { rmPicMsg('Uploading picture ' + n + ' of ' + total + '...'); }).then(function(out) {
                    if (input) input.value = '';
                    var after = function() {
                        rmPicMsg(out.done + ' picture' + (out.done === 1 ? '' : 's') + ' added.' + (out.failed.length ? ' Not added: ' + out.failed.join('; ') : ''), out.failed.length > 0);
                        rmLoadPictures();
                        refreshRoomsGrid();
                    };
                    if (!hadMain && out.firstUrl) {
                        // no main picture yet: the first new one becomes it
                        rmPicRequest('GET', 'api/room-pictures.php?room_id=' + encodeURIComponent(roomId)).then(function(g) {
                            var pic = ((g.data && g.data.gallery) || []).filter(function(p) { return p.image_url === out.firstUrl; })[0];
                            if (!pic) { after(); return; }
                            rmPicRequest('PUT', 'api/room-pictures.php?action=set_featured', { room_id: roomId, picture_id: pic.id }).then(function(res) {
                                if (res.success) rmSetMainUrl(out.firstUrl);
                                after();
                            });
                        });
                    } else {
                        after();
                    }
                });
            }

            // Add room: the pictures chosen in the box upload once the room exists (needs its id).
            function rmAfterAdd(res, form) {
                var input = document.getElementById('addPictureFiles');
                var list = document.getElementById('addPicturesList');
                var typedMain = (form.elements['image_url'].value || '').trim();
                var files = input.files ? Array.prototype.slice.call(input.files) : [];
                var finish = function(extra) {
                    form.reset();
                    document.getElementById('addChildMode').dispatchEvent(new Event('change'));
                    input.value = '';
                    list.textContent = '';
                    if (extra) { var fb = document.getElementById('addRoomFeedback'); fb.className = 'admin-modal-feedback admin-modal-feedback--error visible'; fb.innerHTML = extra; return; }
                    setTimeout(closeAddModal, 1200);
                };
                if (!res.room_id || !files.length) { finish(''); return; }
                var roomId = res.room_id;
                rmUploadFiles(roomId, files, function(n, total) { list.textContent = 'Uploading picture ' + n + ' of ' + total + '...'; }).then(function(out) {
                    var wrapUp = function() {
                        refreshRoomsGrid();
                        finish(out.failed.length ? 'Room added, but these pictures were not: ' + out.failed.map(escapeHtml).join('; ') + '. Open Edit Room to add them again.' : '');
                    };
                    if (!typedMain && out.firstUrl) {
                        fetch('api/room-pictures.php?room_id=' + roomId, { credentials: 'same-origin' }).then(function(r) { return r.json(); }).then(function(g) {
                            var pic = ((g.data && g.data.gallery) || []).filter(function(p) { return p.image_url === out.firstUrl; })[0];
                            if (!pic) { wrapUp(); return; }
                            rmPicRequest('PUT', 'api/room-pictures.php?action=set_featured', { room_id: roomId, picture_id: pic.id }).then(wrapUp);
                        }).catch(wrapUp);
                    } else {
                        wrapUp();
                    }
                });
            }

            // ===== DRAG AND DROP (pictures) =====
            // Drag a thumbnail onto another to move it (the earlier/later buttons still work for touch and keyboard).
            // Drop picture files from the computer onto the Pictures area to upload them.
            var rmPicDragFrom = null;
            function rmHasFiles(e) {
                var t = e.dataTransfer && e.dataTransfer.types;
                return !!t && Array.prototype.indexOf.call(t, 'Files') >= 0;
            }
            function rmClearPicMarks() {
                document.querySelectorAll('#editPicturesGrid .rm-pic').forEach(function(c) { c.classList.remove('rm-pic--over', 'rm-pic--dragging'); });
            }
            (function() {
                var pg = document.getElementById('editPicturesGrid');
                pg.addEventListener('dragstart', function(e) {
                    var card = e.target.closest ? e.target.closest('.rm-pic[data-id]') : null;
                    if (!card) { e.preventDefault(); return; }
                    rmPicDragFrom = Number(card.dataset.i);
                    card.classList.add('rm-pic--dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', 'rm-picture-' + card.dataset.id);
                });
                pg.addEventListener('dragover', function(e) {
                    if (rmPicDragFrom === null) return; // a file drag is handled by the whole Pictures area
                    var card = e.target.closest('.rm-pic[data-id]');
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    document.querySelectorAll('#editPicturesGrid .rm-pic--over').forEach(function(c) { c.classList.remove('rm-pic--over'); });
                    if (card && Number(card.dataset.i) !== rmPicDragFrom) card.classList.add('rm-pic--over');
                });
                pg.addEventListener('drop', function(e) {
                    if (rmPicDragFrom === null) return;
                    e.preventDefault();
                    e.stopPropagation();
                    var card = e.target.closest('.rm-pic[data-id]');
                    var from = rmPicDragFrom;
                    rmPicDragFrom = null;
                    rmClearPicMarks();
                    if (card) rmMovePicture(from, Number(card.dataset.i));
                });
                pg.addEventListener('dragend', function() { rmPicDragFrom = null; rmClearPicMarks(); });

                var sec = document.getElementById('editPicturesSection');
                sec.addEventListener('dragover', function(e) {
                    if (!rmHasFiles(e)) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'copy';
                    sec.classList.add('rm-drop-on');
                });
                sec.addEventListener('dragleave', function(e) {
                    if (!e.relatedTarget || !sec.contains(e.relatedTarget)) sec.classList.remove('rm-drop-on');
                });
                sec.addEventListener('drop', function(e) {
                    if (!rmHasFiles(e)) return;
                    e.preventDefault();
                    sec.classList.remove('rm-drop-on');
                    rmEditUpload(e.dataTransfer.files, null);
                });

                // Add room: drop pictures onto the Pictures box; they join the ones already chosen.
                var addInput = document.getElementById('addPictureFiles');
                var addSec = addInput.closest('.form-section');
                addSec.addEventListener('dragover', function(e) {
                    if (!rmHasFiles(e)) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'copy';
                    addSec.classList.add('rm-drop-on');
                });
                addSec.addEventListener('dragleave', function(e) {
                    if (!e.relatedTarget || !addSec.contains(e.relatedTarget)) addSec.classList.remove('rm-drop-on');
                });
                addSec.addEventListener('drop', function(e) {
                    if (!rmHasFiles(e)) return;
                    e.preventDefault();
                    addSec.classList.remove('rm-drop-on');
                    var list = document.getElementById('addPicturesList');
                    var dropped = Array.prototype.slice.call(e.dataTransfer.files);
                    var problem = rmCheckFiles({ files: dropped });
                    if (problem) { list.textContent = problem; list.className = 'rm-pic-msg is-error'; return; }
                    var dt = new DataTransfer();
                    Array.prototype.slice.call(addInput.files).concat(dropped).forEach(function(f) { dt.items.add(f); });
                    addInput.files = dt.files;
                    list.className = 'rm-pic-msg';
                    list.textContent = dt.files.length + ' picture' + (dt.files.length === 1 ? '' : 's') + ' ready; they upload when you add the room.';
                });

                // A file dropped anywhere else must not make the browser open it and leave this screen.
                if (!window.__rmNoFileNav) {
                    window.__rmNoFileNav = true;
                    ['dragover', 'drop'].forEach(function(ev) {
                        window.addEventListener(ev, function(e) { if (rmHasFiles(e)) e.preventDefault(); });
                    });
                }
            })();

            // ===== FORM CHECKS + 2-DECIMAL MONEY (shared helpers in js/admin-form-guard.js) =====
            // The server checks the same rules again when saving; these messages just appear sooner.
            function rmRulesFor(p) {
                var id = function(n) { return p + n; };
                return [
                    { el: id('Name'), label: 'Room name', required: true, maxLen: 100 },
                    { el: id('ShortDesc'), label: 'Short description', maxLen: 255 },
                    { el: id('Price'), label: 'Price per night', required: true, gt: 0 },
                    { el: id('PriceSingle'), label: 'Single occupancy price', min: 0 },
                    { el: id('PriceDouble'), label: 'Double occupancy price', min: 0 },
                    { el: id('PriceTriple'), label: 'Triple occupancy price', min: 0 },
                    { el: id('ChildPct'), label: 'Child percentage', min: 0, max: 100 },
                    { el: id('ChildAmt'), label: 'Child amount per night', required: document.getElementById(id('ChildMode')).value === 'fixed', min: 0 },
                    { el: id('ChildFree'), label: 'Free children', int: true, min: 0, max: 20 },
                    { el: id('ChildMax'), label: 'Most children in one room', int: true, min: 0, max: 20 },
                    { el: id('Size'), label: 'Size (sqm)', min: 0 },
                    { el: id('Guests'), label: 'Max guests', required: true, int: true, min: 1, max: 20 },
                    { el: id('BedType'), label: 'Bed type', maxLen: 50 },
                    { el: id('Total'), label: 'Total rooms', required: true, int: true, min: 1 },
                    { el: id('Available'), label: 'Rooms available', required: true, int: true, min: 0, lte: id('Total'), lteLabel: 'total rooms' },
                    { el: id('Order'), label: 'Display order', int: true, min: 0 }
                ];
            }
            (function() {
                if (!window.RHFormGuard) return;
                ['edit', 'add'].forEach(function(p) {
                    var form = document.getElementById(p + 'Form');
                    // the child amount is only required while "fixed amount" is chosen, so its rule is read live
                    var rules = rmRulesFor(p);
                    rules.forEach(function(r) {
                        if (r.el === p + 'ChildAmt') {
                            Object.defineProperty(r, 'required', { get: function() { return document.getElementById(p + 'ChildMode').value === 'fixed'; } });
                        }
                    });
                    rules.push({ el: p + 'PictureFiles', label: 'Pictures', file: { types: RM_TYPES, maxBytes: RM_MAX_BYTES } });
                    RHFormGuard.validateForm(form, rules);
                    RHFormGuard.moneyInputs(form);
                });
            })();

            // ===== FACEBOOK DEFAULTS =====
            window._fbDefaults = {
                baseUrl: <?php echo json_encode(rtrim(defined('BASE_URL') ? BASE_URL : '', '/')); ?>,
                currency: <?php echo json_encode(getSetting('currency_symbol', 'MWK')); ?>,
                hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury')); ?>,
                pageName: <?php echo json_encode(getSetting('facebook_page_name', '')); ?>
            };

            window._fbAllRooms = <?php echo json_encode(array_map(function ($r) {
                                        return [
                                            'id'         => (int) $r['id'],
                                            'name'       => $r['name'],
                                            'slug'       => $r['slug'] ?? '',
                                            'price'      => number_format((float)($r['price_per_night'] ?? 0), 0),
                                            'size_sqm'   => $r['size_sqm'] ?? '',
                                            'max_guests' => $r['max_guests'] ?? '',
                                            'image_url'  => $r['image_url'] ?? '',
                                        ];
                                    }, $rooms ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

            // ===== TOGGLE & DELETE =====
            function rhConfirmToggle(msg, fn) { // a mis-tap must not switch a live room/conference status
                if (!window.AdminConfirm) { fn(); return; }
                window.AdminConfirm.request({ title: 'Please confirm', message: msg, confirmText: 'Yes, change it', cancelText: 'Cancel', tone: 'warning', icon: 'fa-toggle-on' }).then(function (ok) { if (ok) fn(); });
            }
            function toggleActive(id) { rhConfirmToggle('Switch this room type on or off for guests?', function () { _toggleActiveNow(id); }); }
            function _toggleActiveNow(id) {
                var fd = new FormData();
                fd.append('action', 'toggle_active');
                fd.append('id', id);
                fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        if (r.ok) window.location.reload();
                        else alert('Error');
                    })
                    .catch(function() {
                        alert('Error');
                    });
            }

            function toggleFeatured(id) { rhConfirmToggle('Change whether this room is featured on the website?', function () { _toggleFeaturedNow(id); }); }
            function _toggleFeaturedNow(id) {
                var fd = new FormData();
                fd.append('action', 'toggle_featured');
                fd.append('id', id);
                fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        if (r.ok) window.location.reload();
                        else alert('Error');
                    })
                    .catch(function() {
                        alert('Error');
                    });
            }

            function deleteRoom(id) {
                var fd = new FormData();
                fd.append('action', 'delete_room');
                fd.append('id', id);
                fetch(window.location.href, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        if (r.ok) window.location.reload();
                        else alert('Error');
                    })
                    .catch(function() {
                        alert('Error');
                    });
            }

            // ===== DRAG AND DROP (room cards) =====
            // Pointer events on the grip, so it works with a mouse, a finger or a pen (HTML5 drag does not fire
            // on touch). The arrow keys also move a focused grip. The new order saves when the card is let go.
            var rmDrag = null;
            var grid = document.getElementById('roomsGrid');

            function rmCardOrder() {
                return Array.from(document.querySelectorAll('#roomsGrid .room-card')).map(function(c) {
                    return c.dataset.id;
                });
            }

            function rmShowOrder() {
                document.querySelectorAll('#roomsGrid .room-card').forEach(function(card, idx) {
                    var badge = card.querySelector('.order-badge');
                    if (badge) badge.textContent = '#' + idx;
                });
            }

            if (grid) {
                grid.addEventListener('pointerdown', function(e) {
                    var handle = e.target.closest('.drag-handle');
                    if (!handle || (e.pointerType === 'mouse' && e.button !== 0)) return;
                    var card = handle.closest('.room-card');
                    if (!card) return;
                    e.preventDefault();
                    rmDrag = { card: card, handle: handle, id: e.pointerId, before: rmCardOrder().join(',') };
                    card.classList.add('dragging');
                    document.body.classList.add('rm-dragging');
                    // listen on the document: moving the card in the grid would drop a pointer capture on the grip
                    document.addEventListener('pointermove', rmDragMove, { passive: false });
                    document.addEventListener('pointerup', rmDragEnd);
                    document.addEventListener('pointercancel', rmDragEnd);
                });

                var rmDragMove = function(e) {
                    if (!rmDrag || e.pointerId !== rmDrag.id) return;
                    e.preventDefault();
                    // scroll the page when the pointer nears the top or bottom edge
                    if (e.clientY < 70) window.scrollBy(0, -18);
                    else if (e.clientY > window.innerHeight - 70) window.scrollBy(0, 18);
                    var under = document.elementFromPoint(e.clientX, e.clientY);
                    var target = under ? under.closest('.room-card') : null;
                    if (!target || target === rmDrag.card || target.parentNode !== grid) return;
                    var cards = Array.from(grid.querySelectorAll('.room-card'));
                    if (cards.indexOf(rmDrag.card) < cards.indexOf(target)) grid.insertBefore(rmDrag.card, target.nextSibling);
                    else grid.insertBefore(rmDrag.card, target);
                    rmShowOrder();
                };

                var rmDragEnd = function(e) {
                    if (!rmDrag || e.pointerId !== rmDrag.id) return;
                    var d = rmDrag;
                    rmDrag = null;
                    d.card.classList.remove('dragging');
                    document.body.classList.remove('rm-dragging');
                    document.removeEventListener('pointermove', rmDragMove);
                    document.removeEventListener('pointerup', rmDragEnd);
                    document.removeEventListener('pointercancel', rmDragEnd);
                    if (e.type === 'pointercancel' || rmCardOrder().join(',') === d.before) { if (e.type === 'pointercancel') refreshRoomsGrid(); return; }
                    saveOrder();
                };

                // Keyboard: up/left moves a card earlier, down/right later.
                grid.addEventListener('keydown', function(e) {
                    var handle = e.target.closest ? e.target.closest('.drag-handle') : null;
                    if (!handle) return;
                    var dir = (e.key === 'ArrowUp' || e.key === 'ArrowLeft') ? -1 : ((e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : 0);
                    if (!dir) return;
                    e.preventDefault();
                    var card = handle.closest('.room-card');
                    var other = dir < 0 ? card.previousElementSibling : card.nextElementSibling;
                    if (!other) return;
                    if (dir < 0) grid.insertBefore(card, other);
                    else grid.insertBefore(card, other.nextSibling);
                    handle.focus();
                    rmShowOrder();
                    saveOrder();
                });
            }

            function saveOrder() {
                var order = rmCardOrder();
                var fd = new FormData();
                fd.append('action', 'update_order');
                fd.append('order', JSON.stringify(order));

                fetch(window.location.pathname, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (data.success) rmShowOrder();
                        else { alert(data.message || 'The new order could not be saved.'); refreshRoomsGrid(); }
                    })
                    .catch(function() {
                        alert('The new order could not be saved. Please try again.');
                        refreshRoomsGrid();
                    });
            }

            // ===== CLOSE MODALS ON OUTSIDE CLICK =====
            // The click must start AND end on the dark area (admin-main.js drops a drag that began inside the
            // box), so selecting text and letting go outside no longer closes the form. Escape closes the top one.
            document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.style.display = 'none';
                    }
                });
            });
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                var open = Array.from(document.querySelectorAll('#roomsGrid ~ .modal-overlay, .content > .modal-overlay')).filter(function(m) {
                    return m.style.display === 'flex';
                });
                if (open.length) open[open.length - 1].style.display = 'none';
            });

            // Upload area drag & drop for images
            document.querySelectorAll('.upload-area').forEach(function(area) {
                area.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    area.classList.add('dragover');
                });
                area.addEventListener('dragleave', function() {
                    area.classList.remove('dragover');
                });
                area.addEventListener('drop', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    area.classList.remove('dragover');
                    var form = area.closest('form');
                    var input = form ? form.querySelector('input[type="file"]') : null;
                    var f = e.dataTransfer.files;
                    if (!input || !f.length) return;
                    var label = area.querySelector('p');
                    var bad = '';
                    if (RM_TYPES.indexOf(f[0].type) < 0) bad = f[0].name + ': only JPG, PNG or WEBP pictures are allowed.';
                    else if (f[0].size > RM_MAX_BYTES) bad = f[0].name + ' is too large. The most allowed is ' + (RM_MAX_BYTES / 1048576).toFixed(0) + ' MB.';
                    if (bad) { input.value = ''; if (label) { label.textContent = bad; label.style.color = '#a12a2a'; } return; }
                    var one = new DataTransfer();
                    one.items.add(f[0]);
                    input.files = one.files;
                    if (label) { label.textContent = f[0].name + ' ready - press the button to upload'; label.style.color = '#3e3930'; }
                });
            });

            // Helper
            function escapeHtml(str) {
                if (!str) return '';
                return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }

            // ── AJAX saves for Edit & Add modals ──────────────────────────────────
            function handleRoomFormSubmit(formId, saveBtnId, feedbackId) {
                var form = document.getElementById(formId);
                var saveBtn = document.getElementById(saveBtnId);
                var fb = document.getElementById(feedbackId);
                var origHtml = saveBtn.innerHTML;
                saveBtn.disabled = true;
                saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
                fetch(window.location.pathname, {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: new FormData(form)
                    })
                    .then(function(r) {
                        if (!r.ok) throw new Error('HTTP ' + r.status);
                        return r.json();
                    })
                    .then(function(res) {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = origHtml;
                        fb.style.width = '100%';
                        fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                        fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                        if (res.success) {
                            refreshRoomsGrid();
                            if (formId === 'addForm') rmAfterAdd(res, form);
                        }
                    })
                    .catch(function() {
                        saveBtn.disabled = false;
                        saveBtn.innerHTML = origHtml;
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                    });
            }
            document.getElementById('editForm').addEventListener('submit', function(e) {
                e.preventDefault();
                handleRoomFormSubmit('editForm', 'editRoomSaveBtn', 'editRoomFeedback');
            });
            document.getElementById('addForm').addEventListener('submit', function(e) {
                e.preventDefault();
                handleRoomFormSubmit('addForm', 'addRoomSaveBtn', 'addRoomFeedback');
            });

            // A duplicate room-type name is flagged while typing (api/check-unique.php). Saving is
            // AJAX with its own busy button, so the guard only blocks a flagged form.
            (function() {
                if (!window.RHFormGuard) return;
                RHFormGuard.watch(document.getElementById('editName'), function() {
                    return { form: 'room_type_name', excludeId: document.getElementById('editId').value };
                });
                RHFormGuard.watch(document.getElementById('addName'), function() {
                    return { form: 'room_type_name' };
                });
                RHFormGuard.guardSubmit(document.getElementById('editForm'), { busy: false });
                RHFormGuard.guardSubmit(document.getElementById('addForm'), { busy: false });
            })();

            function refreshRoomsGrid() {
                fetch(window.location.href)
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(html) {
                        var doc = new DOMParser().parseFromString(html, 'text/html');
                        var next = doc.getElementById('roomsGrid');
                        var cur = document.getElementById('roomsGrid');
                        if (next && cur) cur.innerHTML = next.innerHTML;
                    }).catch(function() {});
            }

            // ── Facebook share modal ──────────────────────────────────────────────
            var _fbRoomId = 0;
            var _fbHasImage = false;
            var _fbCurrentImgUrl = '';

            function updateFbPreview() {
                var caption = document.getElementById('fbShareCaption').value || '';
                var showImg = document.getElementById('fbIncludeImage').checked;

                // Caption text (HTML-escape newlines → <br>)
                var previewText = document.getElementById('fbPreviewText');
                if (previewText) {
                    var safe = caption
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/\n/g, '<br>');
                    previewText.innerHTML = safe ||
                        '<em style="color:#65676b;font-style:italic;">Caption will appear here\u2026</em>';
                }

                // Image preview
                var previewImg = document.getElementById('fbPreviewImg');
                if (previewImg) {
                    if (showImg && _fbCurrentImgUrl) {
                        previewImg.src = _fbCurrentImgUrl;
                        previewImg.style.display = 'block';
                    } else {
                        previewImg.style.display = 'none';
                    }
                }

                // Character counter
                var counter = document.getElementById('fbCharCount');
                if (counter) {
                    var len = caption.length;
                    counter.textContent = len + ' chars';
                    counter.style.color = len > 500 ? '#d32f2f' : '#65676b';
                }
            }

            function openFbShareModal(roomId, roomName, imageUrl, price, slug) {
                _fbRoomId = roomId;
                _fbHasImage = (typeof imageUrl === 'string' && imageUrl !== '');
                _fbCurrentImgUrl = imageUrl || '';

                var modal = document.getElementById('fbShareModal');
                if (!modal) return;

                document.getElementById('fbShareTitle').textContent = 'Share "' + roomName + '" on Facebook';

                // Build caption
                var d = window._fbDefaults || {};
                var caption = roomName + '\n';
                if (price && price !== '0') caption += 'From ' + (d.currency || 'MWK') + ' ' + price + '/night\n';
                caption += '\nBook now: ' + (d.baseUrl || '') + '/room.php?room=' + encodeURIComponent(slug || '') + '\n\n' + (d.hashtags || '');
                document.getElementById('fbShareCaption').value = caption;

                // Include image toggle
                var imgRow = document.getElementById('fbIncludeImageRow');
                if (imgRow) imgRow.style.display = _fbHasImage ? 'flex' : 'none';
                document.getElementById('fbIncludeImage').checked = _fbHasImage;

                // Page name in preview header
                var pn = document.getElementById('fbPreviewPageName');
                if (pn) pn.textContent = (d.pageName && d.pageName !== '') ? d.pageName : 'Your Facebook Page';

                // Reset feedback
                document.getElementById('fbShareFeedback').className = 'admin-modal-feedback';
                document.getElementById('fbShareFeedback').innerHTML = '';

                // Initial preview render
                updateFbPreview();
                modal.style.display = 'flex';
            }

            function closeFbShareModal() {
                var modal = document.getElementById('fbShareModal');
                if (modal) modal.style.display = 'none';
            }

            document.addEventListener('DOMContentLoaded', function() {
                // Wire live preview updates
                var captionArea = document.getElementById('fbShareCaption');
                var imgCheckbox = document.getElementById('fbIncludeImage');
                if (captionArea) captionArea.addEventListener('input', updateFbPreview);
                if (imgCheckbox) imgCheckbox.addEventListener('change', updateFbPreview);

                // Post submit
                var submitBtn = document.getElementById('fbShareSubmitBtn');
                if (!submitBtn) return;
                submitBtn.addEventListener('click', function() {
                    var caption = (document.getElementById('fbShareCaption').value || '').trim();
                    var fb = document.getElementById('fbShareFeedback');
                    if (!caption) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                        return;
                    }
                    submitBtn.disabled = true;
                    var origHtml = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';

                    var formData = new FormData();
                    formData.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
                    formData.append('type', 'room');
                    formData.append('id', String(_fbRoomId));
                    formData.append('message', caption);
                    formData.append('include_image', document.getElementById('fbIncludeImage').checked ? '1' : '0');

                    fetch('api/facebook-post.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            if (data.success) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                var linkHtml = data.post_url ?
                                    ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' :
                                    '';
                                fb.innerHTML = '<i class="fas fa-check-circle"></i> Posted to Facebook!' + linkHtml;
                            } else {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                            }
                        })
                        .catch(function() {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                        });
                });
            });
            // ── Facebook Share ALL Rooms modal ────────────────────────────────────
            var _fbAllFeaturedImgUrl = '';

            function _fbEsc(s) {
                return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function openFbAllRoomsModal() {
                var modal = document.getElementById('fbAllRoomsModal');
                if (!modal) return;
                var d = window._fbDefaults || {};
                var rooms = window._fbAllRooms || [];

                // Build room selection list
                var listEl = document.getElementById('fbAllRoomList');
                listEl.innerHTML = '';
                rooms.forEach(function(room) {
                    var imgSrc = room.image_url || '';
                    if (imgSrc && !/^https?:\/\//i.test(imgSrc)) {
                        imgSrc = (d.baseUrl || '') + '/' + imgSrc.replace(/^\/+/, '');
                    }
                    var item = document.createElement('label');
                    item.className = 'fb-room-select-item checked';
                    item.setAttribute('for', 'fbAllRoom_' + room.id);
                    item.innerHTML =
                        '<input type="checkbox" id="fbAllRoom_' + room.id + '" checked value="' + room.id + '"' +
                        ' data-img="' + _fbEsc(imgSrc) + '"' +
                        ' data-name="' + _fbEsc(room.name) + '"' +
                        ' data-price="' + _fbEsc(room.price) + '"' +
                        ' data-size="' + _fbEsc(String(room.size_sqm || '')) + '"' +
                        ' data-guests="' + _fbEsc(String(room.max_guests || '')) + '">' +
                        (imgSrc ?
                            '<img class="fb-room-select-thumb" src="' + _fbEsc(imgSrc) + '" alt="" loading="lazy" onerror="this.style.visibility=\'hidden\'">' :
                            '<div class="fb-room-select-thumb"></div>') +
                        '<div class="fb-room-select-info">' +
                        '<div class="fb-room-select-name">' + _fbEsc(room.name) + '</div>' +
                        '<div class="fb-room-select-price">' + _fbEsc(d.currency || 'MWK') + ' ' + _fbEsc(room.price) + '/night</div>' +
                        '</div>';
                    // Toggle checked class when checkbox changes
                    var cb = item.querySelector('input');
                    cb.addEventListener('change', function() {
                        item.classList.toggle('checked', this.checked);
                        fbAllRebuildCaption();
                    });
                    listEl.appendChild(item);
                });

                // Set page name in preview
                var pn = document.getElementById('fbAllPreviewPageName');
                if (pn) pn.textContent = (d.pageName && d.pageName !== '') ? d.pageName : 'Your Facebook Page';

                document.getElementById('fbAllFeedback').className = 'admin-modal-feedback';
                document.getElementById('fbAllFeedback').innerHTML = '';
                fbAllRebuildCaption();
                modal.style.display = 'flex';
            }

            function closeFbAllRoomsModal() {
                var m = document.getElementById('fbAllRoomsModal');
                if (m) m.style.display = 'none';
            }

            function fbAllSelectAll(state) {
                document.querySelectorAll('#fbAllRoomList input[type="checkbox"]').forEach(function(cb) {
                    cb.checked = state;
                    cb.closest('.fb-room-select-item').classList.toggle('checked', state);
                });
                fbAllRebuildCaption();
            }

            function fbAllRebuildCaption() {
                var d = window._fbDefaults || {};
                var currency = d.currency || 'MWK';
                var baseUrl = d.baseUrl || '';
                var hashtags = d.hashtags || '';

                var selected = [];
                document.querySelectorAll('#fbAllRoomList input[type="checkbox"]:checked').forEach(function(cb) {
                    selected.push({
                        name: cb.dataset.name,
                        price: cb.dataset.price,
                        size: cb.dataset.size,
                        guests: cb.dataset.guests,
                        img: cb.dataset.img,
                    });
                });

                var countEl = document.getElementById('fbAllRoomCount');
                if (countEl) countEl.textContent = selected.length + ' room' + (selected.length !== 1 ? 's' : '') + ' selected';

                var hotelName = (d.pageName && d.pageName !== '') ? d.pageName : <?php echo json_encode((string)(getSetting('site_name') ?: 'Hotel'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
                var lines = ['🏨 ' + hotelName + ' — Our Rooms', ''];
                selected.forEach(function(r) {
                    lines.push('🛏 ' + r.name);
                    var parts = [];
                    if (r.price && r.price !== '0') parts.push(currency + ' ' + r.price + '/night');
                    if (r.size) parts.push(r.size + ' sqm');
                    if (r.guests) parts.push('Max ' + r.guests + ' guests');
                    if (parts.length) lines.push('   ' + parts.join(' · '));
                    lines.push('');
                });
                if (selected.length > 0) {
                    lines.push('📅 Book now: ' + baseUrl + '/rooms-showcase.php');
                    lines.push('');
                    lines.push(hashtags);
                }

                var captionArea = document.getElementById('fbAllCaption');
                if (captionArea) captionArea.value = lines.join('\n').trim();

                // Determine featured image (first selected room that has one)
                _fbAllFeaturedImgUrl = '';
                for (var i = 0; i < selected.length; i++) {
                    if (selected[i].img) {
                        _fbAllFeaturedImgUrl = selected[i].img;
                        break;
                    }
                }

                fbAllUpdatePreview();
            }

            function fbAllUpdatePreview() {
                var caption = (document.getElementById('fbAllCaption') || {}).value || '';
                var showImg = document.getElementById('fbAllIncludeImage') && document.getElementById('fbAllIncludeImage').checked;

                var previewText = document.getElementById('fbAllPreviewText');
                if (previewText) {
                    var safe = caption
                        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                        .replace(/\n/g, '<br>');
                    previewText.innerHTML = safe ||
                        '<em style="color:#65676b;font-style:italic;">Select rooms above to build the preview\u2026</em>';
                }

                var previewImg = document.getElementById('fbAllPreviewImg');
                if (previewImg) {
                    if (showImg && _fbAllFeaturedImgUrl) {
                        previewImg.src = _fbAllFeaturedImgUrl;
                        previewImg.style.display = 'block';
                    } else {
                        previewImg.style.display = 'none';
                    }
                }

                var counter = document.getElementById('fbAllCharCount');
                if (counter) {
                    var len = caption.length;
                    counter.textContent = len + ' chars';
                    counter.style.color = len > 600 ? '#d32f2f' : '#65676b';
                }
            }

            document.addEventListener('DOMContentLoaded', function() {
                var captionArea = document.getElementById('fbAllCaption');
                var imgCheckbox = document.getElementById('fbAllIncludeImage');
                if (captionArea) captionArea.addEventListener('input', fbAllUpdatePreview);
                if (imgCheckbox) imgCheckbox.addEventListener('change', fbAllUpdatePreview);

                var submitBtn = document.getElementById('fbAllSubmitBtn');
                if (!submitBtn) return;
                submitBtn.addEventListener('click', function() {
                    var caption = (document.getElementById('fbAllCaption').value || '').trim();
                    var fb = document.getElementById('fbAllFeedback');
                    if (!caption) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Caption cannot be empty.';
                        return;
                    }
                    var checkedIds = [];
                    document.querySelectorAll('#fbAllRoomList input[type="checkbox"]:checked').forEach(function(cb) {
                        checkedIds.push(cb.value);
                    });
                    if (checkedIds.length === 0) {
                        fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please select at least one room.';
                        return;
                    }
                    submitBtn.disabled = true;
                    var origHtml = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting\u2026';
                    fb.className = 'admin-modal-feedback';
                    fb.innerHTML = '';

                    var formData = new FormData();
                    formData.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
                    formData.append('type', 'rooms_all');
                    formData.append('room_ids', JSON.stringify(checkedIds));
                    formData.append('message', caption);
                    formData.append('include_image', document.getElementById('fbAllIncludeImage').checked ? '1' : '0');

                    fetch('api/facebook-post.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: formData
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            if (data.success) {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--success visible';
                                var linkHtml = data.post_url ?
                                    ' <a href="' + data.post_url + '" target="_blank" rel="noopener">View post</a>' :
                                    '';
                                fb.innerHTML = '<i class="fas fa-check-circle"></i> All rooms posted to Facebook!' + linkHtml;
                            } else {
                                fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                                fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.');
                            }
                        })
                        .catch(function() {
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = origHtml;
                            fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                            fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error \u2014 please try again.';
                        });
                });
            });
        </script>

        <!-- Facebook Share Modal (with live preview) -->
        <div class="modal-overlay" id="fbShareModal" style="display:none;" onclick="if(event.target===this)closeFbShareModal()">
            <div class="modal-content" style="max-width:860px;width:96vw;">
                <div class="modal-header">
                    <h3 id="fbShareTitle"><i class="fab fa-facebook-f"></i> Share on Facebook</h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closeFbShareModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:24px;align-items:start;">

                        <!-- LEFT: Compose -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                <i class="fas fa-pencil-alt" style="color:#1877F2;margin-right:6px;font-size:0.8rem;"></i>
                                Compose post
                            </div>
                            <div class="form-group" style="margin-bottom:6px;">
                                <textarea id="fbShareCaption" class="fb-caption-preview" rows="9"
                                    style="width:100%;resize:vertical;font-family:inherit;font-size:0.875rem;line-height:1.6;box-sizing:border-box;"
                                    placeholder="Write your post caption here&hellip;"></textarea>
                                <div style="text-align:right;font-size:0.75rem;margin-top:3px;" id="fbCharCount">0 chars</div>
                            </div>

                            <div class="fb-include-image-row" id="fbIncludeImageRow"
                                style="display:none;align-items:center;gap:10px;padding:10px 12px;background:#f0f2f5;border-radius:8px;margin-bottom:10px;">
                                <input type="checkbox" id="fbIncludeImage" value="1" checked style="width:16px;height:16px;cursor:pointer;flex-shrink:0;">
                                <label for="fbIncludeImage" style="cursor:pointer;font-size:0.875rem;margin:0;">
                                    <i class="fas fa-image" style="color:#1877F2;margin-right:4px;"></i>
                                    Include room image in post
                                </label>
                            </div>

                            <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:10px 12px;font-size:0.8rem;color:#856404;">
                                <i class="fas fa-circle-info"></i>
                                <strong>Tip:</strong> Posts with images get significantly more reach on Facebook. Review the preview on the right before posting.
                            </div>
                        </div>

                        <!-- RIGHT: Live Facebook preview -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                <i class="fab fa-facebook-f" style="color:#1877F2;margin-right:6px;"></i>
                                Post preview
                            </div>
                            <div style="border:1px solid #e4e6eb;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1);font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
                                <!-- Post header -->
                                <div style="display:flex;align-items:center;gap:10px;padding:12px 16px 8px;">
                                    <div style="width:40px;height:40px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;">
                                        <i class="fab fa-facebook-f"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div id="fbPreviewPageName"
                                            style="font-weight:700;font-size:0.88rem;color:#1c1e21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            Your Page
                                        </div>
                                        <div style="font-size:0.72rem;color:#65676b;">
                                            Just now &middot; <i class="fas fa-globe-africa" style="font-size:0.65rem;"></i>
                                        </div>
                                    </div>
                                </div>
                                <!-- Caption text (live) -->
                                <div id="fbPreviewText"
                                    style="padding:0 16px 10px;font-size:0.875rem;line-height:1.55;color:#1c1e21;word-break:break-word;white-space:pre-wrap;">
                                    <em style="color:#65676b;font-style:italic;">Caption will appear here&hellip;</em>
                                </div>
                                <!-- Post image (live) -->
                                <img id="fbPreviewImg" src="" alt="Room image"
                                    style="width:100%;display:none;object-fit:cover;max-height:280px;border-top:1px solid #e4e6eb;">
                                <!-- Reactions bar -->
                                <div style="border-top:1px solid #e4e6eb;display:flex;">
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-thumbs-up"></i> Like
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-comment"></i> Comment
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="fas fa-share"></i> Share
                                    </div>
                                </div>
                            </div>
                            <p style="font-size:0.72rem;color:#aaa;margin-top:6px;text-align:center;font-style:italic;">
                                Approximate preview &mdash; actual appearance may vary
                            </p>
                        </div>
                    </div>
                    <div id="fbShareFeedback" class="admin-modal-feedback" style="margin-top:14px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeFbShareModal()">Cancel</button>
                    <button type="button" class="btn fb-btn btn-primary" id="fbShareSubmitBtn">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                </div>
            </div>
        </div>

        <!-- Facebook Share All Rooms Modal -->
        <div class="modal-overlay" id="fbAllRoomsModal" style="display:none;" onclick="if(event.target===this)closeFbAllRoomsModal()">
            <div class="modal-content" style="max-width:920px;width:96vw;">
                <div class="modal-header">
                    <h3>
                        <i class="fab fa-facebook-f"></i> Share All Rooms on Facebook
                    </h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closeFbAllRoomsModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:24px;align-items:start;">

                        <!-- LEFT: Room picker + caption editor -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:10px;color:#1c1e21;">
                                <i class="fas fa-check-square" style="color:#1877F2;margin-right:6px;"></i>
                                Choose rooms to include
                            </div>
                            <div class="fb-room-select-list" id="fbAllRoomList"></div>
                            <div style="display:flex;align-items:center;gap:8px;margin-top:10px;flex-wrap:wrap;">
                                <button type="button" onclick="fbAllSelectAll(true)"
                                    style="background:none;border:1px solid #d1d5db;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:0.8rem;font-family:inherit;">
                                    Select all
                                </button>
                                <button type="button" onclick="fbAllSelectAll(false)"
                                    style="background:none;border:1px solid #d1d5db;border-radius:5px;padding:4px 10px;cursor:pointer;font-size:0.8rem;font-family:inherit;">
                                    Clear all
                                </button>
                                <span id="fbAllRoomCount" style="color:#6b7280;font-size:0.82rem;margin-left:auto;">0 rooms</span>
                            </div>

                            <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e5e7eb;">
                                <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                    <i class="fas fa-pencil-alt" style="color:#1877F2;margin-right:6px;font-size:0.8rem;"></i>
                                    Caption <small style="font-weight:400;color:#6b7280;">(edit freely before posting)</small>
                                </div>
                                <textarea id="fbAllCaption" rows="9"
                                    style="width:100%;resize:vertical;font-size:0.875rem;line-height:1.6;box-sizing:border-box;padding:10px 12px;border:1px solid #d0d7de;border-radius:6px;font-family:inherit;"
                                    placeholder="Your post caption will be generated here from the selected rooms&hellip;"></textarea>
                                <div style="display:flex;align-items:center;gap:8px;margin-top:6px;flex-wrap:wrap;">
                                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:0.875rem;flex:1;">
                                        <input type="checkbox" id="fbAllIncludeImage" checked style="width:16px;height:16px;cursor:pointer;accent-color:#1877F2;">
                                        <i class="fas fa-image" style="color:#1877F2;font-size:0.85rem;"></i>
                                        Include featured room image
                                    </label>
                                    <span id="fbAllCharCount" style="font-size:0.75rem;color:#65676b;white-space:nowrap;">0 chars</span>
                                </div>
                            </div>
                        </div>

                        <!-- RIGHT: Live Facebook preview -->
                        <div>
                            <div style="font-weight:600;font-size:0.875rem;margin-bottom:8px;color:#1c1e21;">
                                <i class="fab fa-facebook-f" style="color:#1877F2;margin-right:6px;"></i>
                                Live post preview
                            </div>
                            <div style="border:1px solid #e4e6eb;border-radius:8px;overflow:hidden;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.1);font-family:'Segoe UI',Helvetica,Arial,sans-serif;">
                                <!-- Header -->
                                <div style="display:flex;align-items:center;gap:10px;padding:12px 16px 8px;">
                                    <div style="width:40px;height:40px;border-radius:50%;background:#1877F2;display:flex;align-items:center;justify-content:center;color:#fff;font-size:1rem;flex-shrink:0;">
                                        <i class="fab fa-facebook-f"></i>
                                    </div>
                                    <div style="min-width:0;">
                                        <div id="fbAllPreviewPageName" style="font-weight:700;font-size:0.88rem;color:#1c1e21;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                            Your Page
                                        </div>
                                        <div style="font-size:0.72rem;color:#65676b;">
                                            Just now &middot; <i class="fas fa-globe-africa" style="font-size:0.65rem;"></i>
                                        </div>
                                    </div>
                                </div>
                                <!-- Caption (live) -->
                                <div id="fbAllPreviewText"
                                    style="padding:0 16px 10px;font-size:0.875rem;line-height:1.55;color:#1c1e21;word-break:break-word;white-space:pre-wrap;max-height:340px;overflow-y:auto;">
                                    <em style="color:#65676b;font-style:italic;">Select rooms above to build the preview&hellip;</em>
                                </div>
                                <!-- Image (live) -->
                                <img id="fbAllPreviewImg" src="" alt="Room image"
                                    style="width:100%;display:none;object-fit:cover;max-height:240px;border-top:1px solid #e4e6eb;">
                                <!-- Reactions bar -->
                                <div style="border-top:1px solid #e4e6eb;display:flex;">
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-thumbs-up"></i> Like
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="far fa-comment"></i> Comment
                                    </div>
                                    <div style="flex:1;padding:8px 4px;text-align:center;font-size:0.82rem;color:#65676b;font-weight:600;user-select:none;">
                                        <i class="fas fa-share"></i> Share
                                    </div>
                                </div>
                            </div>
                            <p style="font-size:0.72rem;color:#aaa;margin-top:6px;text-align:center;font-style:italic;">
                                Approximate preview &mdash; actual appearance may vary on Facebook
                            </p>
                        </div>
                    </div>
                    <div id="fbAllFeedback" class="admin-modal-feedback" style="margin-top:14px;"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeFbAllRoomsModal()">Cancel</button>
                    <button type="button" class="btn fb-btn btn-primary" id="fbAllSubmitBtn">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                </div>
            </div>
        </div>

        </div><!-- /.content -->

        <?php require_once 'includes/admin-footer.php'; ?>

