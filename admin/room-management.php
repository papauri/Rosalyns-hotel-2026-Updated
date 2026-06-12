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

            if ($rooms_available > $total_rooms) {
                $error = 'Availability cannot exceed total rooms.';
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
                $stmt->execute([
                    $_POST['name'],
                    $description,
                    $_POST['short_description'] ?? '',
                    $_POST['price_per_night'],
                    $_POST['price_single_occupancy'] ?? null,
                    $_POST['price_double_occupancy'] ?? null,
                    $_POST['price_triple_occupancy'] ?? null,
                    $_POST['child_price_multiplier'] ?? 50,
                    $singleEnabled,
                    $doubleEnabled,
                    $tripleEnabled,
                    $childrenAllowed,
                    $_POST['size_sqm'] ?? 0,
                    $maxGuests,
                    $rooms_available,
                    $total_rooms,
                    $_POST['bed_type'] ?? 'Double',
                    $_POST['amenities'] ?? '',
                    $room_image_url,
                    isset($_POST['is_featured']) ? 1 : 0,
                    isset($_POST['is_active']) ? 1 : 0,
                    $_POST['display_order'] ?? 0,
                    $_POST['id']
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
                $room_id = $_POST['room_id'];
                $allowed = ['jpg', 'jpeg', 'png', 'webp'];
                $filename = $_FILES['room_image']['name'];
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $size = (int)($_FILES['room_image']['size'] ?? 0);

                if ($size > 20 * 1024 * 1024) {
                    $error = 'File too large. Max size is 20MB.';
                    if (is_ajax_request()) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $error]);
                        exit;
                    }
                }

                $mime_ok = true;
                if (function_exists('finfo_open')) {
                    $f = finfo_open(FILEINFO_MIME_TYPE);
                    if ($f) {
                        $mime = finfo_file($f, $_FILES['room_image']['tmp_name']);
                        finfo_close($f);
                        $mime_ok = in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true);
                    }
                }

                if (in_array($ext, $allowed) && $mime_ok) {
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
                    child_price_multiplier,
                    single_occupancy_enabled, double_occupancy_enabled, triple_occupancy_enabled, children_allowed,
                    size_sqm, max_guests, rooms_available, total_rooms,
                    bed_type, amenities, image_url, is_featured, is_active, display_order, video_path, video_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $maxGuests = max(1, (int)($_POST['max_guests'] ?? 2));
            $singleEnabled = $maxGuests >= 1 ? 1 : 0;
            $doubleEnabled = $maxGuests >= 2 ? 1 : 0;
            $tripleEnabled = ($maxGuests >= 3 && isset($_POST['triple_occupancy_enabled'])) ? 1 : 0;
            $childrenAllowed = isset($_POST['children_allowed']) ? 1 : 0;
            $stmt->execute([
                $_POST['name'],
                $_POST['description'] ?? $_POST['short_description'] ?? '',
                $_POST['short_description'] ?? '',
                $_POST['price_per_night'] ?? 0,
                $_POST['price_single_occupancy'] ?? null,
                $_POST['price_double_occupancy'] ?? null,
                $_POST['price_triple_occupancy'] ?? null,
                $_POST['child_price_multiplier'] ?? 50,
                $singleEnabled,
                $doubleEnabled,
                $tripleEnabled,
                $childrenAllowed,
                $_POST['size_sqm'] ?? 0,
                $maxGuests,
                $_POST['rooms_available'] ?? 1,
                $_POST['total_rooms'] ?? 1,
                $_POST['bed_type'] ?? 'Double',
                $_POST['amenities'] ?? '',
                $room_image_url,
                isset($_POST['is_featured']) ? 1 : 0,
                1,
                $_POST['display_order'] ?? 0,
                $videoPath,
                $videoType
            ]);

            $newRoomId = (int)$pdo->lastInsertId();
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
                echo json_encode(['success' => true, 'message' => $message]);
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
            $room_id = $_POST['id'];

            $stmt = $pdo->prepare("SELECT image_url FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);
            $old_image = $stmt->fetchColumn();

            // Delete canonical room gallery images
            try {
                $stmt = $pdo->prepare("SELECT image_url FROM gallery WHERE room_id = ?");
                $stmt->execute([$room_id]);
                $gallery_images = $stmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($gallery_images as $img) {
                    if ($img && !preg_match('#^https?://#i', $img)) {
                        $path = '../' . $img;
                        if (file_exists($path)) @unlink($path);
                    }
                }

                $stmt = $pdo->prepare("DELETE FROM gallery WHERE room_id = ?");
                $stmt->execute([$room_id]);
            } catch (PDOException $e) {
                // Continue room deletion even if optional gallery cleanup fails.
            }

            $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
            $stmt->execute([$room_id]);

            if ($old_image && !preg_match('#^https?://#i', $old_image)) {
                $path = '../' . $old_image;
                if (file_exists($path)) @unlink($path);
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
    } catch (PDOException $e) {
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
$fb_rooms_posting_on = getSetting('facebook_posting_enabled', '0') === '1'
    && getSetting('facebook_rooms_enabled', '1') === '1'
    && getSetting('facebook_page_access_token', '') !== '';
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
    <script>
        (function() {
            var _t = '<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>';
            var _f = window.fetch;
            window.fetch = function(u, o) {
                if (o && o.body instanceof FormData && !o.body.has('csrf_token')) o.body.append('csrf_token', _t);
                return _f.apply(this, arguments);
            };
        })();
    </script>
    <title>Room Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css">
    <link rel="stylesheet" href="css/admin-components.css">
    <link rel="stylesheet" href="css/room-management.css">
    <link rel="stylesheet" href="css/facebook-settings.css">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header-row">
            <h2 class="page-title"><i class="fas fa-bed"></i> Manage Hotel Rooms</h2>
            <div style="display:flex; gap:10px; align-items:center;">
                <span style="font-size:12px; color:#999;"><i class="fas fa-arrows-alt"></i> Drag cards to reorder</span>
                <button class="btn-action" type="button" style="background:var(--gold,#8B7355); color:var(--deep-navy,#111111); padding:12px 24px; font-size:14px; border-radius:8px;" onclick="openAddModal()">
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
            <div class="fb-share-all-banner">
                <div class="fb-share-all-banner__icon"><i class="fab fa-facebook-f"></i></div>
                <div class="fb-share-all-banner__text">
                    <div class="fb-share-all-banner__title">Share All Rooms on Facebook</div>
                    <div class="fb-share-all-banner__sub">Compose one post showcasing all your rooms — pick which to include, preview exactly how it'll look, then post.</div>
                </div>
                <button class="fb-share-all-btn" type="button" onclick="openFbAllRoomsModal()">
                    <i class="fab fa-facebook-f"></i> Compose &amp; Preview Post
                </button>
            </div>
        <?php endif; ?>

        <?php if (!empty($rooms)): ?>
            <div class="room-cards-grid" id="roomsGrid">
                <?php foreach ($rooms as $room): ?>
                    <div class="room-card" data-id="<?php echo $room['id']; ?>" draggable="true">
                        <div class="drag-handle"><i class="fas fa-grip-vertical"></i></div>
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
                                    $avail = $room['rooms_available'] ?? 0;
                                    $total = $room['total_rooms'] ?? 0;
                                    echo $avail . '/' . $total . ' avail';
                                    ?>
                                </div>
                            </div>

                            <?php if ($room['price_single_occupancy'] || $room['price_double_occupancy'] || $room['price_triple_occupancy']): ?>
                                <div style="font-size:11px; color:#888; margin-bottom:10px;">
                                    <?php if ($room['price_single_occupancy']): ?>Single: <?php echo $currency; ?> <?php echo number_format($room['price_single_occupancy'], 0); ?> <?php endif; ?>
                                <?php if ($room['price_double_occupancy']): ?>| Double: <?php echo $currency; ?> <?php echo number_format($room['price_double_occupancy'], 0); ?> <?php endif; ?>
                            <?php if ($room['price_triple_occupancy']): ?>| Triple: <?php echo $currency; ?> <?php echo number_format($room['price_triple_occupancy'], 0); ?> <?php endif; ?>
                        | Child: +<?php echo number_format((float)($room['child_price_multiplier'] ?? 50), 0); ?>%
                                </div>
                            <?php endif; ?>

                            <div class="room-card-meta">
                                <?php if ($room['is_active']): ?>
                                    <span class="room-badge badge-active"><i class="fas fa-check"></i> Active</span>
                                <?php else: ?>
                                    <span class="room-badge badge-inactive"><i class="fas fa-times"></i> Inactive</span>
                                <?php endif; ?>
                                <?php if ($room['is_featured']): ?>
                                    <span class="room-badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                                <?php endif; ?>
                                <?php if (!empty($room['video_path'])): ?>
                                    <span class="room-badge badge-video"><i class="fas fa-video"></i> Video</span>
                                <?php endif; ?>
                            </div>

                            <div class="room-card-actions">
                                <!-- Icon toolbar -->
                                <div class="rmc-toolbar">
                                    <button class="rmc-icon-btn" type="button"
                                        title="<?php echo !empty($room['image_url']) ? 'Change featured image' : 'Add a featured image'; ?>"
                                        onclick="openImageModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($room['image_url'] ?? '', ENT_QUOTES); ?>')">
                                        <i class="fas fa-image"></i>
                                    </button>
                                    <button class="rmc-icon-btn" type="button"
                                        title="Manage gallery photos (lightbox / photo tour)"
                                        onclick="openPicturesModal(<?php echo $room['id']; ?>, '<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>')">
                                        <i class="fas fa-images"></i>
                                    </button>
                                    <button class="rmc-icon-btn" type="button"
                                        title="<?php echo !empty($room['video_path']) ? 'Replace or remove the room video' : 'Upload a video tour (MP4/WebM)'; ?>"
                                        onclick='openVideoModal(<?php echo $room["id"]; ?>, <?php echo htmlspecialchars(json_encode($room["name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["video_path"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["video_type"] ?? ""), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-video"></i>
                                    </button>

                                    <span class="rmc-toolbar-sep"></span>

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

                                    <?php if ($fb_rooms_posting_on): ?>
                                        <span class="rmc-toolbar-sep"></span>
                                        <button class="rmc-icon-btn rmc-icon-btn--facebook" type="button"
                                            title="Preview &amp; post this room to your Facebook Page"
                                            onclick='openFbShareModal(<?php echo (int)$room["id"]; ?>, <?php echo htmlspecialchars(json_encode($room["name"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["image_url"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode(number_format((float)($room["price_per_night"] ?? 0))), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($room["slug"] ?? ""), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fab fa-facebook-f"></i>
                                        </button>
                                    <?php endif; ?>

                                    <span class="rmc-spacer"></span>

                                    <button class="rmc-icon-btn rmc-icon-btn--danger" type="button"
                                        title="Delete &quot;<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>&quot; permanently — cannot be undone"
                                        onclick="if(confirm('Delete &quot;<?php echo htmlspecialchars($room['name'], ENT_QUOTES); ?>&quot; permanently?\n\nThis removes all images, videos and data. Cannot be undone.')) deleteRoom(<?php echo $room['id']; ?>)">
                                        <i class="fas fa-trash-alt"></i>
                                    </button>
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
                <div class="modal-header">
                    <h3 id="editModalTitle"><i class="fas fa-edit"></i> Edit Room</h3>
                    <button class="modal-close" type="button" onclick="closeEditModal()">&times;</button>
                </div>
                <form method="POST" id="editForm">
                    <input type="hidden" name="action" id="editAction" value="update">
                    <input type="hidden" name="id" id="editId">

                    <div class="modal-body">
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                            <div class="form-group">
                                <label>Room Name *</label>
                                <input type="text" name="name" id="editName" required>
                            </div>
                            <div class="form-group">
                                <label>Short Description</label>
                                <textarea name="short_description" id="editShortDesc" rows="2"></textarea>
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
                                    <input type="number" name="price_per_night" id="editPrice" step="0.01" required data-currency="<?php echo $currency; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Single Occupancy Price</label>
                                    <input type="number" name="price_single_occupancy" id="editPriceSingle" step="0.01" placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Double Occupancy Price</label>
                                    <input type="number" name="price_double_occupancy" id="editPriceDouble" step="0.01" placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Triple Occupancy Price</label>
                                    <input type="number" name="price_triple_occupancy" id="editPriceTriple" step="0.01" placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Child Supplement (%)</label>
                                    <input type="number" name="child_price_multiplier" id="editChildPriceMultiplier" step="0.01" min="0" placeholder="50">
                                </div>
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
                                    <input type="number" name="size_sqm" id="editSize" min="0">
                                </div>
                                <div class="form-group">
                                    <label>Max Guests</label>
                                    <input type="number" name="max_guests" id="editGuests" min="1" value="2">
                                </div>
                                <div class="form-group">
                                    <label>Bed Type</label>
                                    <input type="text" name="bed_type" id="editBedType" placeholder="e.g. King Bed">
                                </div>
                            </div>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label>Rooms Available</label>
                                    <input type="number" name="rooms_available" id="editAvailable" min="0">
                                </div>
                                <div class="form-group">
                                    <label>Total Rooms</label>
                                    <input type="number" name="total_rooms" id="editTotal" min="1">
                                </div>
                                <div class="form-group">
                                    <label>Display Order</label>
                                    <input type="number" name="display_order" id="editOrder" min="0" value="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Amenities (comma-separated)</label>
                                <textarea name="amenities" id="editAmenities" rows="2" placeholder="WiFi, Air Conditioning, TV, Mini Bar"></textarea>
                            </div>
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

                    <div class="form-actions">
                        <div id="editRoomFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                        <button type="button" onclick="closeEditModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
                        <button type="submit" id="editRoomSaveBtn" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold,#8B7355); color:var(--deep-navy,#111111); font-weight:600; cursor:pointer;">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Add Room Modal -->
        <div class="modal-overlay" id="addModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3><i class="fas fa-plus-circle"></i> Add New Room</h3>
                    <button class="modal-close" type="button" onclick="closeAddModal()">&times;</button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="addForm">
                    <input type="hidden" name="action" value="add_room">

                    <div class="modal-body">
                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-info-circle"></i> Room Information</div>
                            <div class="form-group">
                                <label>Room Name *</label>
                                <input type="text" name="name" required>
                            </div>
                            <div class="form-group">
                                <label>Short Description</label>
                                <textarea name="short_description" rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Full Description</label>
                                <textarea name="description" rows="3"></textarea>
                            </div>
                            <div class="form-group">
                                <label>Featured Image URL / Path</label>
                                <input type="text" name="image_url" placeholder="relative/path/to/image.jpg or https://cdn.example.com/room.jpg">
                                <small class="helper-text">Optional. You can also upload via the image modal after creating the room.</small>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="fas fa-dollar-sign"></i> Pricing</div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Price Per Night *</label>
                                    <input type="number" name="price_per_night" step="0.01" required data-currency="<?php echo $currency; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Single Occupancy Price</label>
                                    <input type="number" name="price_single_occupancy" step="0.01" placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                </div>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label>Double Occupancy Price</label>
                                    <input type="number" name="price_double_occupancy" step="0.01" placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Triple Occupancy Price</label>
                                    <input type="number" name="price_triple_occupancy" step="0.01" placeholder="Optional" data-currency="<?php echo $currency; ?>">
                                </div>
                                <div class="form-group">
                                    <label>Child Supplement (%)</label>
                                    <input type="number" name="child_price_multiplier" step="0.01" min="0" value="50">
                                </div>
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
                                    <input type="number" name="size_sqm">
                                </div>
                                <div class="form-group">
                                    <label>Max Guests</label>
                                    <input type="number" name="max_guests" value="2">
                                </div>
                                <div class="form-group">
                                    <label>Bed Type</label>
                                    <input type="text" name="bed_type" value="Double">
                                </div>
                            </div>
                            <div class="form-row-3">
                                <div class="form-group">
                                    <label>Rooms Available</label>
                                    <input type="number" name="rooms_available" value="1">
                                </div>
                                <div class="form-group">
                                    <label>Total Rooms</label>
                                    <input type="number" name="total_rooms" value="1">
                                </div>
                                <div class="form-group">
                                    <label>Display Order</label>
                                    <input type="number" name="display_order" value="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Amenities (comma-separated)</label>
                                <textarea name="amenities" rows="2" placeholder="WiFi, Air Conditioning, TV, Mini Bar"></textarea>
                            </div>
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

                    <div class="form-actions">
                        <div id="addRoomFeedback" class="admin-modal-feedback" style="width:100%;margin-bottom:8px;"></div>
                        <button type="button" onclick="closeAddModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
                        <button type="submit" id="addRoomSaveBtn" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold,#8B7355); color:var(--deep-navy,#111111); font-weight:600; cursor:pointer;">
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
                    <button class="modal-close" type="button" onclick="closeImageModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <div id="currentImageContainer" style="display:none; margin-bottom:20px;">
                        <h4 style="margin-bottom:12px; color:#666;">Current Image</h4>
                        <img id="currentImage" class="current-image" alt="Current room image">
                    </div>
                    <h4 style="margin:0 0 12px; color:#666;">Upload New Image</h4>
                    <form method="POST" enctype="multipart/form-data" id="imageUploadForm">
                        <input type="hidden" name="action" value="upload_image">
                        <input type="hidden" name="room_id" id="uploadRoomId">

                        <div class="upload-area" onclick="document.getElementById('roomImageInput').click()">
                            <i class="fas fa-cloud-upload-alt"></i>
                            <p style="margin:8px 0; color:#666;">Click or drag an image here</p>
                            <small style="color:#999;">JPG, PNG, WEBP (Max 20MB)</small>
                        </div>
                        <div class="form-group" style="margin-top:12px;">
                            <input type="file" name="room_image" id="roomImageInput" accept="image/jpeg,image/png,image/webp" required>
                        </div>
                    </form>

                    <div class="image-source-divider"><span>OR</span></div>

                    <form method="POST" id="imageUrlForm" class="image-source-panel">
                        <input type="hidden" name="action" value="update_image_url">
                        <input type="hidden" name="room_id" id="imageUrlRoomId">
                        <label class="image-source-label" for="imageUrlInput">Set Image URL / Path</label>
                        <input type="text" name="image_url_input" id="imageUrlInput" class="form-control" placeholder="relative/path/to/image.jpg or https://cdn.example.com/room_hero.jpg">
                        <button type="submit" class="btn-action btn-image-source-save"><i class="fas fa-link"></i> Save URL/Path</button>
                    </form>
                </div>
                <div class="form-actions">
                    <button type="button" onclick="closeImageModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
                </div>
            </div>
        </div>

        <!-- Video Modal -->
        <div class="modal-overlay" id="videoModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3 id="videoModalTitle"><i class="fas fa-video"></i> Room Video</h3>
                    <button class="modal-close" type="button" onclick="closeVideoModal()">&times;</button>
                </div>
                <form method="POST" enctype="multipart/form-data" id="videoForm">
                    <input type="hidden" name="action" value="update_video">
                    <input type="hidden" name="room_id" id="videoRoomId">
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
                    <div class="form-actions">
                        <button type="button" onclick="closeVideoModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Cancel</button>
                        <button type="submit" style="padding:10px 24px; border:none; border-radius:6px; background:#007bff; color:white; font-weight:600; cursor:pointer;">
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
                    <button class="modal-close" type="button" onclick="closePicturesModal()">&times;</button>
                </div>
                <div class="modal-body">
                    <form method="POST" enctype="multipart/form-data" id="pictureUploadForm" style="margin-bottom:20px; padding-bottom:16px; border-bottom:1px solid #eee;">
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
                            <button type="submit" class="btn-action" style="background:var(--gold,#8B7355); color:var(--deep-navy); padding:8px 16px;">
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

        <script>
            // ===== EDIT MODAL =====
            function openEditModal(room) {
                document.getElementById('editModalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit: ' + escapeHtml(room.name);
                document.getElementById('editAction').value = 'update';
                document.getElementById('editId').value = room.id;
                document.getElementById('editName').value = room.name || '';
                document.getElementById('editShortDesc').value = room.short_description || '';
                document.getElementById('editDescription').value = room.description || '';
                document.getElementById('editPrice').value = room.price_per_night || '';
                document.getElementById('editPriceSingle').value = room.price_single_occupancy || '';
                document.getElementById('editPriceDouble').value = room.price_double_occupancy || '';
                document.getElementById('editPriceTriple').value = room.price_triple_occupancy || '';
                document.getElementById('editChildPriceMultiplier').value = room.child_price_multiplier || 50;
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
                document.getElementById('editModal').style.display = 'flex';
            }

            function closeEditModal() {
                document.getElementById('editModal').style.display = 'none';
                document.getElementById('editRoomFeedback').className = 'admin-modal-feedback';
                document.getElementById('editRoomFeedback').innerHTML = '';
            }

            // ===== ADD MODAL =====
            function openAddModal() {
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
                            'X-Requested-With': 'XMLHttpRequest'
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
                            'X-Requested-With': 'XMLHttpRequest'
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
                            'X-Requested-With': 'XMLHttpRequest'
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

            // ===== FACEBOOK DEFAULTS =====
            window._fbDefaults = {
                baseUrl: <?php echo json_encode(rtrim(defined('BASE_URL') ? BASE_URL : '', '/')); ?>,
                currency: <?php echo json_encode(getSetting('currency_symbol', 'MWK')); ?>,
                hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #accommodation')); ?>,
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
            function toggleActive(id) {
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

            function toggleFeatured(id) {
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

            // ===== DRAG AND DROP =====
            var dragSrcEl = null;
            var grid = document.getElementById('roomsGrid');

            if (grid) {
                grid.addEventListener('dragstart', function(e) {
                    if (!e.target.closest('.drag-handle')) {
                        e.preventDefault();
                        return;
                    }
                    var card = e.target.closest('.room-card');
                    if (!card) return;
                    dragSrcEl = card;
                    card.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.dataset.id);
                });

                grid.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    var card = e.target.closest('.room-card');
                    if (card && card !== dragSrcEl) {
                        grid.querySelectorAll('.room-card').forEach(function(c) {
                            c.classList.remove('drag-over');
                        });
                        card.classList.add('drag-over');
                    }
                });

                grid.addEventListener('dragleave', function(e) {
                    var card = e.target.closest('.room-card');
                    if (card) card.classList.remove('drag-over');
                });

                grid.addEventListener('drop', function(e) {
                    e.preventDefault();
                    var targetCard = e.target.closest('.room-card');
                    if (!targetCard || !dragSrcEl || targetCard === dragSrcEl) return;

                    var cards = Array.from(grid.querySelectorAll('.room-card'));
                    var srcIdx = cards.indexOf(dragSrcEl);
                    var targetIdx = cards.indexOf(targetCard);

                    if (srcIdx < targetIdx) {
                        targetCard.parentNode.insertBefore(dragSrcEl, targetCard.nextSibling);
                    } else {
                        targetCard.parentNode.insertBefore(dragSrcEl, targetCard);
                    }

                    saveOrder();
                });

                grid.addEventListener('dragend', function() {
                    grid.querySelectorAll('.room-card').forEach(function(c) {
                        c.classList.remove('dragging', 'drag-over');
                    });
                    dragSrcEl = null;
                });
            }

            function saveOrder() {
                var cards = document.querySelectorAll('.room-card');
                var order = Array.from(cards).map(function(c) {
                    return c.dataset.id;
                });

                var fd = new FormData();
                fd.append('action', 'update_order');
                fd.append('order', JSON.stringify(order));

                fetch(window.location.href, {
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
                        if (data.success) {
                            cards.forEach(function(card, idx) {
                                var badge = card.querySelector('.order-badge');
                                if (badge) badge.textContent = '#' + idx;
                            });
                        }
                    })
                    .catch(function(err) {
                        console.error('Error saving order:', err);
                    });
            }

            // ===== CLOSE MODALS ON OUTSIDE CLICK =====
            document.querySelectorAll('.modal-overlay').forEach(function(modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === this) {
                        this.style.display = 'none';
                    }
                });
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
                    if (input && e.dataTransfer.files.length) input.files = e.dataTransfer.files;
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
                        if (res.success) refreshRoomsGrid();
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

                var hotelName = (d.pageName && d.pageName !== '') ? d.pageName : "Rosalyn's Beach Hotel";
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
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 id="fbShareTitle" style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Share on Facebook</h3>
                    <button class="modal-close" type="button" onclick="closeFbShareModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding:20px 24px;">
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
                                <i class="fas fa-lightbulb"></i>
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
                    <button type="button" class="btn fb-btn" id="fbShareSubmitBtn">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbShareModal()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- Facebook Share All Rooms Modal -->
        <div class="modal-overlay" id="fbAllRoomsModal" style="display:none;" onclick="if(event.target===this)closeFbAllRoomsModal()">
            <div class="modal-content" style="max-width:920px;width:96vw;">
                <div class="modal-header" style="border-top:4px solid #1877F2;">
                    <h3 style="color:#1877F2;display:flex;align-items:center;gap:8px;">
                        <i class="fab fa-facebook-f"></i> Share All Rooms on Facebook
                    </h3>
                    <button class="modal-close" type="button" onclick="closeFbAllRoomsModal()">&times;</button>
                </div>
                <div class="modal-body" style="padding:20px 24px;">
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
                    <button type="button" class="btn fb-btn" id="fbAllSubmitBtn">
                        <i class="fab fa-facebook-f"></i> Post to Facebook Page
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="closeFbAllRoomsModal()">Cancel</button>
                </div>
            </div>
        </div>

        <?php require_once 'includes/admin-footer.php'; ?>

