<?php

/**
 * Gallery Management - Admin Panel
 * Manage hotel gallery images and videos (hotel_gallery table)
 */

require_once 'admin-init.php';
/** @var array<string, mixed> $user */
/** @var string $csrf_token */
require_once '../includes/alert.php';
require_once 'video-upload-handler.php';
require_once '../includes/form-validation.php';

/** Guests read the gallery from a 1-hour cache: every change here must drop it. */
function galleryBustCache(): void
{
    if (function_exists('invalidateDataCaches')) {
        invalidateDataCaches();
    }
}

/**
 * Switch the media-catalog rows that mirror one gallery item on or off (optionally one column only).
 * The catalog keeps its own is_active, so a hidden or removed gallery item must switch it off too.
 */
function galleryCatalogSetActive(int $id, bool $active, ?string $column = null): void
{
    global $pdo;
    if ($id <= 0 || !function_exists('mediaTablesAvailable') || !mediaTablesAvailable()) {
        return;
    }
    $sql = "UPDATE managed_media_catalog c JOIN managed_media_links l ON l.media_catalog_id = c.id
            SET c.is_active = ? WHERE l.source_table = 'hotel_gallery' AND l.source_record_id = ?";
    $args = [$active ? 1 : 0, (string)$id];
    if ($column !== null) {
        $sql .= ' AND l.source_column = ?';
        $args[] = $column;
    }
    $pdo->prepare($sql)->execute($args);
}

/** The gallery item is gone: remove its media links and the catalog rows only it used. */
function galleryCatalogRemove(int $id): void
{
    global $pdo;
    if ($id <= 0 || !function_exists('mediaTablesAvailable') || !mediaTablesAvailable()) {
        return;
    }
    $sel = $pdo->prepare("SELECT DISTINCT media_catalog_id FROM managed_media_links WHERE source_table = 'hotel_gallery' AND source_record_id = ?");
    $sel->execute([(string)$id]);
    $catalogIds = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));
    $pdo->prepare("DELETE FROM managed_media_links WHERE source_table = 'hotel_gallery' AND source_record_id = ?")->execute([(string)$id]);
    $left = $pdo->prepare('SELECT COUNT(*) FROM managed_media_links WHERE media_catalog_id = ?');
    $del = $pdo->prepare('DELETE FROM managed_media_catalog WHERE id = ?');
    foreach ($catalogIds as $cid) {
        $left->execute([$cid]);
        if ((int)$left->fetchColumn() === 0) {
            $del->execute([$cid]);
        }
    }
}

function syncHotelGalleryManagedMedia(array $item): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $id = $item['id'] ?? null;
    if (!$id) {
        return;
    }
    if (array_key_exists('is_active', $item) && !(int)$item['is_active']) {
        galleryCatalogSetActive((int)$id, false); // a hidden item stays out of the media catalog
        return;
    }

    upsertManagedMediaForSource('hotel_gallery', $id, 'image_url', $item['image_url'] ?? null, [
        'title' => ($item['title'] ?? 'Gallery Item') . ' (Image)',
        'description' => $item['description'] ?? null,
        'caption' => $item['description'] ?? null,
        'alt_text' => $item['title'] ?? 'Gallery image',
        'placement_key' => 'index_hotel_gallery',
        'page_slug' => 'index',
        'section_key' => 'hotel_gallery',
        'entity_type' => 'hotel_gallery',
        'entity_id' => (int)$id,
        'display_order' => (int)($item['display_order'] ?? 0),
        'use_case' => 'gallery_image',
        'media_type' => 'image',
    ]);

    upsertManagedMediaForSource('hotel_gallery', $id, 'video_path', $item['video_path'] ?? null, [
        'title' => ($item['title'] ?? 'Gallery Item') . ' (Video)',
        'description' => $item['description'] ?? null,
        'caption' => $item['description'] ?? null,
        'alt_text' => $item['title'] ?? 'Gallery video',
        'placement_key' => 'index_hotel_gallery',
        'page_slug' => 'index',
        'section_key' => 'hotel_gallery',
        'entity_type' => 'hotel_gallery',
        'entity_id' => (int)$id,
        'display_order' => (int)($item['display_order'] ?? 0),
        'use_case' => 'gallery_video',
        'media_type' => 'video',
        'mime_type' => $item['video_type'] ?? null,
    ]);
    // upsert only switches the link off when media is removed; the catalog row must follow
    if (trim((string)($item['video_path'] ?? '')) === '') {
        galleryCatalogSetActive((int)$id, false, 'video_path');
    }
}

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

// Helper: upload gallery image (hardened: size cap + extension + MIME + image content verification)
function uploadGalleryImage(?array $fileInput)
{
    if (!$fileInput || !isset($fileInput['tmp_name']) || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    // Shared size cap (config/security.php) — was a local 8 MB literal.
    if ($gm_sizeError = rh_check_image_upload_size($fileInput, $gm_sizeWarning)) {
        error_log('Gallery upload rejected: ' . $gm_sizeError);
        return null;
    }
    if (!empty($gm_sizeWarning)) {
        error_log('Gallery upload warning: ' . $gm_sizeWarning);
    }
    // MIME validation; the stored extension comes from the verified MIME, never the client name.
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileInput['tmp_name']) ?: '';
    $mimeToExt = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($mimeToExt[$mime])) {
        return null;
    }
    $ext = $mimeToExt[$mime];
    // Confirm it really is an image
    if (!@getimagesize($fileInput['tmp_name'])) {
        return null;
    }
    $uploadDir = __DIR__ . '/../images/hotel_gallery/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    $filename = 'gallery_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $relativePath = 'images/hotel_gallery/' . $filename;
    $destination = $uploadDir . $filename;
    if (move_uploaded_file($fileInput['tmp_name'], $destination)) {
        return $relativePath;
    }
    return null;
}

/** External image link: http(s) or site-relative only (no javascript:/data:/traversal); '' when unusable. */
function galleryCleanImageUrl($value): string
{
    $v = trim((string)$value);
    if ($v === '' || preg_match('#^https?://#i', $v)) {
        return $v;
    }
    if (preg_match('/^[a-z][a-z0-9+.\-]*:/i', $v) || strpos($v, '..') !== false || strpos($v, '//') === 0) {
        return '';
    }
    return ltrim(str_replace('\\', '/', $v), '/');
}

/** True when a file was submitted in $_FILES[$key] (so a silent validation failure can be reported). */
function galleryFileSubmitted(string $key): bool
{
    return isset($_FILES[$key]) && (int)($_FILES[$key]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

/**
 * Remove a local file only when it is one this screen stored itself (images/hotel_gallery/ or
 * videos/gallery/, plain file name). A site path typed into the URL box (a room photo, the logo)
 * or a Media Portal upload is never deleted from here.
 */
function galleryUnlinkLocal(?string $rel): void
{
    $rel = trim((string)$rel);
    if ($rel === '' || preg_match('#^https?://#i', $rel) || strpos($rel, '..') !== false) {
        return;
    }
    if (!preg_match('#^(images/hotel_gallery|videos/gallery)/[A-Za-z0-9._-]+$#', $rel)) {
        return;
    }
    $path = __DIR__ . '/../' . $rel;
    if (is_file($path)) {
        @unlink($path);
    }
}

// Handle POST actions
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$savedId = null;
$gUploaded = []; // files this request stored; removed again if the save does not go through

/** JSON reply for the AJAX calls on this screen. */
function galleryJsonExit(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            galleryJsonExit(['success' => false, 'message' => 'Security token invalid.']);
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        if (in_array($action, ['add', 'update'], true)) {
            $_POST['title'] = rh_clean_text($_POST['title'] ?? '');
            $_POST['category'] = rh_clean_text($_POST['category'] ?? '') ?: 'general';
        }

        if ($action === 'add') {
            // Check everything first so a rejected save never leaves an uploaded file behind.
            $galDup = ($_POST['title'] !== '' && mb_strlen($_POST['title']) <= 255)
                ? rh_find_duplicate($pdo, 'hotel_gallery', 'title', $_POST['title'], ['category' => $_POST['category']])
                : null;
            $addError = '';
            if ($_POST['title'] === '') {
                $addError = 'Title is required.';
            } elseif (mb_strlen($_POST['title']) > 255) {
                $addError = 'Title is too long (max 255 characters).';
            } elseif (mb_strlen($_POST['category']) > 100) {
                $addError = 'Category is too long (max 100 characters).';
            } elseif ($galDup) {
                $addError = 'There is already a gallery item called "' . $galDup['value'] . '" in the "' . $_POST['category'] . '" category. Edit that one or use a different title.';
            }

            $imageUrl = '';
            if ($addError === '') {
                $imagePath = uploadGalleryImage($_FILES['image'] ?? null);
                if ($imagePath) {
                    $gUploaded[] = $imagePath;
                }
                $imageUrl = $imagePath ?: galleryCleanImageUrl($_POST['image_url_external'] ?? '');
                if (empty($imageUrl)) {
                    $addError = galleryFileSubmitted('image')
                        ? 'The image was rejected: use a JPG, PNG, WebP or GIF under 8 MB.'
                        : 'Please provide an image (upload or URL).';
                }
            }

            if ($addError !== '') {
                $error = $addError;
                if ($isAjax) {
                    galleryJsonExit(['success' => false, 'message' => $error]);
                }
            } else {
                // Handle video
                $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
                $videoPath = $videoUrl['path'] ?? null;
                $videoType = $videoUrl['type'] ?? null;

                if (!$videoPath) {
                    $videoUpload = uploadVideo($_FILES['video'] ?? null, 'gallery');
                    $videoPath = $videoUpload['path'] ?? null;
                    $videoType = $videoUpload['type'] ?? null;
                    if ($videoPath) {
                        $gUploaded[] = $videoPath;
                    }
                }

                $newOrder = (int)($_POST['display_order'] ?? 0);
                $stmt = $pdo->prepare("
                    INSERT INTO hotel_gallery (title, description, image_url, video_path, video_type, category, is_active, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $newId = 0;
                rh_with_create_lock($pdo, 'hotel_gallery', function () use ($pdo, $stmt, $imageUrl, $videoPath, $videoType, $newOrder, &$newId) {
                    if ($dupNow = rh_find_duplicate($pdo, 'hotel_gallery', 'title', (string)$_POST['title'], ['category' => $_POST['category']])) {
                        throw new RuntimeException('There is already a gallery item called "' . $dupNow['value'] . '" in the "' . $_POST['category'] . '" category.');
                    }
                    $stmt->execute([
                        (string)$_POST['title'],
                        $_POST['description'] ?? '',
                        $imageUrl,
                        $videoPath,
                        $videoType,
                        $_POST['category'] ?? 'general',
                        1,
                        $newOrder
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                });
                if ($newId > 0) {
                    syncHotelGalleryManagedMedia([
                        'id' => $newId,
                        'title' => $_POST['title'] ?? null,
                        'description' => $_POST['description'] ?? null,
                        'display_order' => $newOrder,
                        'image_url' => $imageUrl,
                        'video_path' => $videoPath,
                        'video_type' => $videoType,
                    ]);
                }
                galleryBustCache();
                $gUploaded = [];

                $message = 'Gallery item added successfully!';
                $savedId = $newId;
                if ($isAjax) {
                    galleryJsonExit(['success' => true, 'message' => $message, 'saved_id' => $savedId]);
                }
            }
        } elseif ($action === 'update') {
            // Validate before anything is uploaded.
            $itemId = (int)($_POST['id'] ?? 0);
            if ($_POST['title'] === '' || $itemId <= 0) {
                throw new RuntimeException('Title and a valid item are required.');
            }
            if (mb_strlen($_POST['title']) > 255 || mb_strlen($_POST['category']) > 100) {
                throw new RuntimeException('Title (max 255) or category (max 100) is too long.');
            }
            if ($galDup = rh_find_duplicate($pdo, 'hotel_gallery', 'title', $_POST['title'], ['category' => $_POST['category']], $itemId)) {
                throw new RuntimeException('There is already a gallery item called "' . $galDup['value'] . '" in the "' . $_POST['category'] . '" category.');
            }
            $curSt = $pdo->prepare('SELECT image_url FROM hotel_gallery WHERE id = ?');
            $curSt->execute([$itemId]);
            $curRow = $curSt->fetch(PDO::FETCH_ASSOC);
            if (!$curRow) {
                throw new RuntimeException('That gallery item no longer exists.');
            }

            $imagePath = uploadGalleryImage($_FILES['image'] ?? null);
            if ($imagePath) {
                $gUploaded[] = $imagePath;
            }
            if (!$imagePath && galleryFileSubmitted('image')) {
                throw new RuntimeException('The image was rejected: use a JPG, PNG, WebP or GIF under 8 MB.');
            }

            // Handle video
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            $videoPath = $videoUrl['path'] ?? null;
            $videoType = $videoUrl['type'] ?? null;

            if (!$videoPath) {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'gallery');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
                if ($videoPath) {
                    $gUploaded[] = $videoPath;
                }
            }

            $updateFields = ['title = ?', 'description = ?', 'category = ?', 'display_order = ?'];
            $updateValues = [
                trim((string)$_POST['title']),
                $_POST['description'] ?? '',
                $_POST['category'] ?? 'general',
                (int)($_POST['display_order'] ?? 0)
            ];

            $oldImageToRemove = null;
            $externalImage = galleryCleanImageUrl($_POST['image_url_external'] ?? '');
            if ($imagePath || $externalImage !== '') {
                $oldImageToRemove = (string)$curRow['image_url'];
                $updateFields[] = 'image_url = ?';
                $updateValues[] = $imagePath ?: $externalImage;
                if ($oldImageToRemove === ($imagePath ?: $externalImage)) {
                    $oldImageToRemove = null;
                }
            }

            if ($videoPath) {
                $updateFields[] = 'video_path = ?';
                $updateValues[] = $videoPath;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = $videoType;
            }

            // Handle remove video
            if (isset($_POST['remove_video']) && $_POST['remove_video'] == '1') {
                $updateFields[] = 'video_path = ?';
                $updateValues[] = null;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = null;
            }

            $updateValues[] = $itemId;

            $sql = "UPDATE hotel_gallery SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);
            $gUploaded = [];
            if ($oldImageToRemove) {
                galleryUnlinkLocal($oldImageToRemove); // replaced image no longer referenced
            }

            $mediaStmt = $pdo->prepare("SELECT id, title, description, display_order, image_url, video_path, video_type, is_active FROM hotel_gallery WHERE id = ? LIMIT 1");
            $mediaStmt->execute([$itemId]);
            $mediaItem = $mediaStmt->fetch(PDO::FETCH_ASSOC);
            if ($mediaItem) {
                syncHotelGalleryManagedMedia($mediaItem);
            }
            galleryBustCache();

            $message = 'Gallery item updated successfully!';
            $savedId = $itemId;
            if ($isAjax) {
                galleryJsonExit(['success' => true, 'message' => $message, 'saved_id' => $savedId]);
            }
        } elseif ($action === 'delete') {
            $itemId = (int)($_POST['id'] ?? 0);

            // Get the files before deleting
            $stmt = $pdo->prepare("SELECT image_url, video_path FROM hotel_gallery WHERE id = ?");
            $stmt->execute([$itemId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            // Row, media links and catalog rows go together or not at all.
            $pdo->beginTransaction();
            try {
                galleryCatalogRemove($itemId);
                $pdo->prepare("DELETE FROM hotel_gallery WHERE id = ?")->execute([$itemId]);
                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }

            // Only files this screen stored are deleted (see galleryUnlinkLocal)
            if ($item) {
                galleryUnlinkLocal($item['image_url']);
                galleryUnlinkLocal($item['video_path']);
            }
            galleryBustCache();
            $message = 'Gallery item deleted successfully!';
        } elseif ($action === 'toggle_active') {
            $itemId = (int)($_POST['id'] ?? 0);
            $curSt = $pdo->prepare('SELECT is_active FROM hotel_gallery WHERE id = ?');
            $curSt->execute([$itemId]);
            $curActive = $curSt->fetchColumn();
            if ($curActive === false) {
                throw new RuntimeException('That gallery item no longer exists.');
            }
            $pdo->prepare("UPDATE hotel_gallery SET is_active = ? WHERE id = ?")->execute([(int)$curActive ? 0 : 1, $itemId]);
            $rowSt = $pdo->prepare("SELECT id, title, description, display_order, image_url, video_path, video_type, is_active FROM hotel_gallery WHERE id = ? LIMIT 1");
            $rowSt->execute([$itemId]);
            if ($row = $rowSt->fetch(PDO::FETCH_ASSOC)) {
                syncHotelGalleryManagedMedia($row); // hidden: catalog rows off; shown again: back on
            }
            galleryBustCache();
            $message = 'Gallery item status updated!';
        } elseif ($action === 'reorder') {
            // Drag-and-drop / move up-down: the full list of ids in the new order.
            $posted = json_decode((string)($_POST['order'] ?? '[]'), true);
            if (!is_array($posted) || !$posted) {
                throw new RuntimeException('No order received.');
            }
            $existing = array_map('intval', $pdo->query('SELECT id FROM hotel_gallery ORDER BY display_order ASC, id ASC')->fetchAll(PDO::FETCH_COLUMN));
            $order = [];
            foreach ($posted as $pid) {
                $pid = (int)$pid;
                if ($pid > 0 && in_array($pid, $existing, true) && !in_array($pid, $order, true)) {
                    $order[] = $pid;
                }
            }
            if (!$order) {
                throw new RuntimeException('Those gallery items no longer exist. Reload the page.');
            }
            foreach ($existing as $eid) { // items added meanwhile keep their place at the end
                if (!in_array($eid, $order, true)) {
                    $order[] = $eid;
                }
            }
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare('UPDATE hotel_gallery SET display_order = ? WHERE id = ?');
                $updMedia = (function_exists('mediaTablesAvailable') && mediaTablesAvailable())
                    ? $pdo->prepare("UPDATE managed_media_catalog c JOIN managed_media_links l ON l.media_catalog_id = c.id SET c.display_order = ?, l.display_order = ? WHERE l.source_table = 'hotel_gallery' AND l.source_record_id = ?")
                    : null;
                foreach ($order as $i => $oid) {
                    $pos = ($i + 1) * 10;
                    $upd->execute([$pos, $oid]);
                    if ($updMedia) {
                        $updMedia->execute([$pos, $pos, (string)$oid]);
                    }
                }
                $pdo->commit();
            } catch (Throwable $txe) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txe;
            }
            galleryBustCache();
            $message = 'Gallery order saved.';
            if ($isAjax) {
                galleryJsonExit(['success' => true, 'message' => $message, 'order' => $order]);
            }
        }
    } catch (Throwable $e) {
        foreach ($gUploaded as $upl) { // a save that failed must not leave its upload behind
            galleryUnlinkLocal($upl);
        }
        $error = 'Error: ' . $e->getMessage();
        if ($isAjax) {
            galleryJsonExit(['success' => false, 'message' => $error]);
        }
    }
}

// Fetch all gallery items
try {
    $stmt = $pdo->query("SELECT * FROM hotel_gallery ORDER BY display_order ASC, id ASC");
    $gallery_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($gallery_items) && function_exists('applyManagedMediaOverrides')) {
        foreach ($gallery_items as &$galleryItemRow) {
            $galleryItemRow = applyManagedMediaOverrides($galleryItemRow, 'hotel_gallery', $galleryItemRow['id'] ?? '', ['image_url', 'video_path']);
        }
        unset($galleryItemRow);
    }
} catch (PDOException $e) {
    $error = 'Error fetching gallery: ' . $e->getMessage();
    $gallery_items = [];
}

// Get unique categories
$categories = array_unique(array_filter(array_column($gallery_items, 'category')));
sort($categories);

$gallery_css_version = (string)@filemtime(__DIR__ . '/css/gallery-management.css');
if ($gallery_css_version === '' || $gallery_css_version === '0') {
    $gallery_css_version = (string)time();
}
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
    <title>Gallery Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/gallery-management.css?v=<?php echo urlencode($gallery_css_version); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header gallery-page-header">
            <div>
                <h2 class="page-title"><i class="fas fa-images"></i> Gallery Management</h2>
                <p class="gallery-page-summary"><?php echo count($gallery_items); ?> items in gallery</p>
            </div>
            <button class="btn-add-gallery" type="button" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add Gallery Item
            </button>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <div id="galleryPageFeedback" class="admin-modal-feedback" role="status" aria-live="polite" hidden></div>
        <?php if (count($gallery_items) > 1): ?>
            <p class="gallery-reorder-hint"><i class="fas fa-grip-vertical"></i> Drag a photo by its handle, or use the up and down buttons, to change the order guests see. The order saves as soon as you let go.</p>
        <?php endif; ?>

        <!-- Category Filter -->
        <div class="filter-bar">
            <button class="filter-btn active" type="button" onclick="filterGallery('all', this)">All</button>
            <?php foreach ($categories as $cat): ?>
                <button class="filter-btn" type="button" onclick="filterGallery('<?php echo htmlspecialchars($cat); ?>', this)">
                    <?php echo htmlspecialchars(ucfirst($cat)); ?>
                </button>
            <?php endforeach; ?>
        </div>

        <!-- Gallery Grid -->
        <?php if (!empty($gallery_items)): ?>
            <div class="gallery-grid" id="galleryGrid">
                <?php foreach ($gallery_items as $item): ?>
                    <div class="gallery-card" data-id="<?php echo (int)$item['id']; ?>" data-category="<?php echo htmlspecialchars($item['category']); ?>">
                        <?php
                        $imgSrc = $item['image_url'];
                        if (!preg_match('#^https?://#i', $imgSrc)) {
                            $imgSrc = '../' . $imgSrc;
                        }
                        ?>
                        <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                            alt="<?php echo htmlspecialchars($item['title']); ?>"
                            class="gallery-card-image"
                            onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                        <div class="no-image-placeholder" style="display:none;"><i class="fas fa-image"></i></div>

                        <div class="gallery-card-body">
                            <div class="gallery-card-title"><?php echo htmlspecialchars($item['title']); ?></div>
                            <div class="gallery-card-desc"><?php echo htmlspecialchars(substr($item['description'] ?? '', 0, 80)); ?></div>

                            <div class="gallery-card-meta">
                                <span class="badge badge-category"><?php echo htmlspecialchars(ucfirst($item['category'] ?? 'general')); ?></span>
                                <?php if ($item['is_active']): ?>
                                    <span class="badge badge-active"><i class="fas fa-check"></i> Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive"><i class="fas fa-times"></i> Inactive</span>
                                <?php endif; ?>
                                <?php if (!empty($item['video_path'])): ?>
                                    <span class="badge badge-video"><i class="fas fa-video"></i> Video</span>
                                <?php endif; ?>
                                <span class="gallery-order">Order: <?php echo $item['display_order']; ?></span>
                            </div>

                            <div class="gallery-card-actions">
                                <div class="gallery-move" role="group" aria-label="Change position">
                                    <span class="gallery-drag-handle" title="Drag to change the order"><i class="fas fa-grip-vertical" aria-hidden="true"></i></span>
                                    <button class="gallery-move-btn" type="button" data-move="-1" title="Move up" aria-label="Move up: <?php echo htmlspecialchars($item['title']); ?>"><i class="fas fa-arrow-up" aria-hidden="true"></i></button>
                                    <button class="gallery-move-btn" type="button" data-move="1" title="Move down" aria-label="Move down: <?php echo htmlspecialchars($item['title']); ?>"><i class="fas fa-arrow-down" aria-hidden="true"></i></button>
                                </div>
                                <button class="btn btn-primary btn-action btn-edit" type="button" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($item), ENT_QUOTES, "UTF-8"); ?>)'>
                                    <i class="fas fa-edit"></i> Edit
                                </button>
                                <button class="btn btn-secondary btn-action btn-toggle-active" type="button" title="Toggle active status" aria-label="Toggle active status" onclick="confirmToggleActive(<?php echo (int)$item['id']; ?>, <?php echo (int)$item['is_active']; ?>, <?php echo htmlspecialchars(json_encode($item['title'] ?? 'Gallery item'), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-power-off"></i> Toggle
                                </button>
                                <button class="btn btn-danger btn-action btn-delete" type="button" title="Delete gallery item" aria-label="Delete gallery item" onclick="confirmDeleteItem(<?php echo (int)$item['id']; ?>, <?php echo htmlspecialchars(json_encode($item['title'] ?? 'Gallery item'), ENT_QUOTES, 'UTF-8'); ?>)">
                                    <i class="fas fa-trash-alt"></i> Delete
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state gallery-empty-state">
                <i class="fas fa-images gallery-empty-state__icon"></i>
                <p>No gallery items found. Click "Add Gallery Item" to get started.</p>
            </div>
        <?php endif; ?>
    </div>

    <!-- Add/Edit Modal -->
    <div class="modal-overlay" id="galleryModal">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="galleryForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="modal-header">
                    <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Gallery Item</h3>
                    <button class="modal-close" type="button" aria-label="Close" onclick="closeModal()">&times;</button>
                </div>
                <div class="modal-body">
                <div class="form-group">
                    <label>Title *</label>
                    <input type="text" name="title" id="formTitle" required>
                </div>

                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="formDescription" rows="2"></textarea>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="formCategory">
                            <option value="general">General</option>
                            <option value="exterior">Exterior</option>
                            <option value="interior">Interior</option>
                            <option value="rooms">Rooms</option>
                            <option value="facilities">Facilities</option>
                            <option value="dining">Dining</option>
                            <option value="events">Events</option>
                            <option value="spa">Spa & Wellness</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" id="formOrder" value="0" min="0">
                    </div>
                </div>

                <!-- Image -->
                <div class="form-group">
                    <label><i class="fas fa-image"></i> Image</label>
                    <div id="currentImagePreview" style="display:none; margin-bottom:8px;">
                        <img id="previewImg" src="" style="max-width:200px; max-height:120px; border-radius:6px; border:1px solid #ddd;">
                    </div>
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/jpg">
                    <div style="margin-top:8px;">
                        <label style="font-size:12px; font-weight:normal;">Or paste an image URL:</label>
                        <input type="url" name="image_url_external" id="formImageUrlExternal" placeholder="https://images.unsplash.com/...">
                    </div>
                </div>

                <!-- Video -->
                <div class="form-group">
                    <label><i class="fas fa-video"></i> Video (Optional)</label>
                    <div id="currentVideoInfo" style="display:none; margin-bottom:8px; background:#f0f7ff; padding:8px 12px; border-radius:6px; font-size:13px;">
                        <i class="fas fa-video" style="color:var(--gold);"></i> <span id="currentVideoText"></span>
                        <label style="display:inline-flex; align-items:center; gap:4px; margin-left:12px; font-weight:normal; cursor:pointer;">
                            <input type="checkbox" name="remove_video" value="1"> Remove video
                        </label>
                    </div>
                    <input type="url" name="video_url" id="formVideoUrl" placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/...">
                    <small style="color:#888;">YouTube, Vimeo, Dailymotion, or direct video URL</small>
                    <div style="text-align:center; color:#999; font-size:11px; margin:8px 0;">— OR upload —</div>
                    <input type="file" name="video" accept="video/*">
                </div>
                </div>
                <div class="form-actions modal-footer">
                    <div id="galleryModalFeedback" class="admin-modal-feedback" style="width:100%;"></div>
                    <button type="button" onclick="closeModal()">Close</button>
                    <button type="submit" id="galleryFormSubmitBtn">
                        <i class="fas fa-save"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="admin-page-loader" class="admin-page-loader" role="status" aria-label="Loading">
        <div class="admin-page-loader-card">
            <div class="admin-page-loader-spinner"><span></span><span></span><span></span></div>
            <p class="admin-page-loader-title">Loading...</p>
        </div>
    </div>

    <script>
        const GALLERY_CSRF_TOKEN = <?php echo json_encode($csrf_token); ?>;

        function setGalleryLoader(visible, label) {
            const loader = document.getElementById('admin-page-loader');
            if (!loader) {
                return;
            }

            const title = loader.querySelector('.admin-page-loader-title');
            if (title && label) {
                title.textContent = label;
            }

            loader.classList.toggle('is-visible', !!visible);
        }

        function showGalleryMessage(isError, message) {
            const safeMessage = String(message)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            if (window.Modal && typeof window.Modal.showMessage === 'function') {
                window.Modal.showMessage({
                    title: isError ? 'Action Failed' : 'Success',
                    message: '<p>' + safeMessage + '</p>',
                    size: 'sm'
                });
                return;
            }

            const feedback = document.getElementById('galleryPageFeedback');
            if (!feedback) {
                return;
            }

            feedback.hidden = false;
            feedback.className = 'admin-modal-feedback ' + (isError ? 'admin-modal-feedback--error' : 'admin-modal-feedback--success') + ' visible';
            feedback.innerHTML = '<i class="fas ' + (isError ? 'fa-exclamation-circle' : 'fa-check-circle') + '"></i> ' + safeMessage;
        }

        function requestGalleryConfirmation(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(options);
            }

            return Promise.resolve(true);
        }

        function postGalleryAction(action, id, loadingText) {
            const formData = new FormData();
            formData.append('action', action);
            formData.append('id', String(id));
            formData.append('csrf_token', GALLERY_CSRF_TOKEN || '');

            setGalleryLoader(true, loadingText);

            return fetch(window.location.href, {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Request failed');
                    }

                    window.location.reload();
                })
                .catch(function() {
                    setGalleryLoader(false, 'Loading...');
                    showGalleryMessage(true, 'Unable to complete this action. Please try again.');
                });
        }

        function openAddModal() {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-plus-circle"></i> Add Gallery Item';
            document.getElementById('formAction').value = 'add';
            document.getElementById('formId').value = '';
            document.getElementById('formTitle').value = '';
            document.getElementById('formDescription').value = '';
            document.getElementById('formCategory').value = 'general';
            document.getElementById('formOrder').value = '0';
            document.getElementById('formImageUrlExternal').value = '';
            document.getElementById('formVideoUrl').value = '';
            document.getElementById('currentImagePreview').style.display = 'none';
            document.getElementById('currentVideoInfo').style.display = 'none';
            document.getElementById('galleryModal').style.display = 'flex';
        }

        function openEditModal(item) {
            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Gallery Item';
            document.getElementById('formAction').value = 'update';
            document.getElementById('formId').value = item.id;
            document.getElementById('formTitle').value = item.title;
            document.getElementById('formDescription').value = item.description || '';
            document.getElementById('formCategory').value = item.category || 'general';
            document.getElementById('formOrder').value = item.display_order || 0;

            // Show current image if exists
            if (item.image_url) {
                const imgSrc = item.image_url.match(/^https?:\/\//) ? item.image_url : '../' + item.image_url;
                document.getElementById('previewImg').src = imgSrc;
                document.getElementById('currentImagePreview').style.display = 'block';
                document.getElementById('formImageUrlExternal').value = item.image_url.match(/^https?:\/\//) ? item.image_url : '';
            } else {
                document.getElementById('currentImagePreview').style.display = 'none';
            }

            // Show current video info
            if (item.video_path) {
                document.getElementById('currentVideoText').textContent = item.video_path.substring(0, 60) + (item.video_path.length > 60 ? '...' : '') + ' (' + (item.video_type || 'unknown') + ')';
                document.getElementById('currentVideoInfo').style.display = 'block';
                if (item.video_path.match(/^https?:\/\//)) {
                    document.getElementById('formVideoUrl').value = item.video_path;
                }
            } else {
                document.getElementById('currentVideoInfo').style.display = 'none';
            }

            document.getElementById('galleryModal').style.display = 'flex';
        }

        function closeModal() {
            document.getElementById('galleryModal').style.display = 'none';
            const fb = document.getElementById('galleryModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        // Close on outside click
        document.getElementById('galleryModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // ── AJAX save — keep modal open ─────────────────────────────
        document.getElementById('galleryForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('galleryFormSubmitBtn');
            const fb = document.getElementById('galleryModalFeedback');
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            setGalleryLoader(true, 'Saving gallery item...');
            fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: new FormData(this)
                })
                .then(function(r) {
                    if (!r.ok) throw new Error('HTTP ' + r.status);
                    return r.json();
                })
                .then(function(res) {
                    setGalleryLoader(false, 'Loading...');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) {
                        if (res.saved_id && document.getElementById('formAction').value === 'add') {
                            document.getElementById('formAction').value = 'update';
                            document.getElementById('formId').value = res.saved_id;
                            document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Gallery Item';
                        }
                        refreshGalleryGrid();
                    }
                })
                .catch(function() {
                    setGalleryLoader(false, 'Loading...');
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = '<i class="fas fa-save"></i> Save';
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        });

        function refreshGalleryGrid() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const next = doc.getElementById('galleryGrid');
                    const cur = document.getElementById('galleryGrid');
                    if (next && cur) cur.innerHTML = next.innerHTML;
                }).catch(function() {});
        }

        function toggleActive(id) {
            return postGalleryAction('toggle_active', id, 'Updating gallery item...');
        }

        function confirmToggleActive(id, isActive, title) {
            requestGalleryConfirmation({
                title: isActive ? 'Deactivate gallery item?' : 'Activate gallery item?',
                message: 'This will update whether the item appears on the website.',
                details: [title || 'Gallery item'],
                confirmText: isActive ? 'Deactivate' : 'Activate',
                cancelText: 'Cancel',
                tone: isActive ? 'warning' : 'success',
                icon: isActive ? 'fa-eye-slash' : 'fa-eye'
            }).then(function(confirmed) {
                if (confirmed) {
                    toggleActive(id);
                }
            });
        }

        function deleteItem(id) {
            return postGalleryAction('delete', id, 'Deleting gallery item...');
        }

        function confirmDeleteItem(id, title) {
            requestGalleryConfirmation({
                title: 'Delete gallery item?',
                message: 'This permanently removes the item from the gallery.',
                details: [title || 'Gallery item'],
                confirmText: 'Delete item',
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'fa-trash-alt'
            }).then(function(confirmed) {
                if (confirmed) {
                    deleteItem(id);
                }
            });
        }

        // ── Reorder: drag (mouse), pointer drag (touch / pen) and Move up / Move down buttons ──
        (function() {
            const grid = document.getElementById('galleryGrid');
            if (!grid || grid.dataset.reorderReady) {
                return;
            }
            grid.dataset.reorderReady = '1';
            let dragCard = null;
            let startOrder = '';
            let touchDrag = false;

            function allCards() {
                return Array.prototype.slice.call(grid.querySelectorAll('.gallery-card'));
            }

            function currentOrder() {
                return allCards().map(function(c) {
                    return c.dataset.id;
                }).join(',');
            }

            function note(isError, text) {
                const fb = document.getElementById('galleryPageFeedback');
                if (!fb) {
                    return;
                }
                fb.hidden = false;
                fb.className = 'admin-modal-feedback ' + (isError ? 'admin-modal-feedback--error' : 'admin-modal-feedback--success') + ' visible';
                fb.textContent = text;
                clearTimeout(note.timer);
                if (!isError) {
                    note.timer = setTimeout(function() {
                        fb.hidden = true;
                    }, 2500);
                }
            }

            function saveOrder() {
                const order = allCards().map(function(c) {
                    return c.dataset.id;
                });
                const fd = new FormData();
                fd.append('action', 'reorder');
                fd.append('order', JSON.stringify(order));
                fd.append('csrf_token', GALLERY_CSRF_TOKEN || '');
                fetch(window.location.pathname, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        if (!r.ok) {
                            throw new Error('HTTP ' + r.status);
                        }
                        return r.json();
                    })
                    .then(function(res) {
                        if (!res.success) {
                            throw new Error(res.message || 'Could not save the order.');
                        }
                        allCards().forEach(function(c, i) {
                            const label = c.querySelector('.gallery-order');
                            if (label) {
                                label.textContent = 'Order: ' + ((i + 1) * 10);
                            }
                        });
                        note(false, 'Gallery order saved.');
                    })
                    .catch(function(err) {
                        note(true, (err && err.message ? err.message : 'Could not save the order.') + ' The page will reload.');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    });
            }

            function placeNear(target, clientX, clientY) {
                if (!dragCard || !target || target === dragCard) {
                    return;
                }
                const r = target.getBoundingClientRect();
                const before = clientY < r.top + r.height * 0.25 ||
                    (clientY <= r.bottom - r.height * 0.25 && clientX < r.left + r.width / 2);
                const ref = before ? target : target.nextElementSibling;
                if (ref !== dragCard.nextElementSibling || before) {
                    grid.insertBefore(dragCard, ref);
                }
            }

            function finishDrag() {
                if (!dragCard) {
                    return;
                }
                dragCard.classList.remove('is-dragging');
                dragCard.removeAttribute('draggable');
                dragCard = null;
                touchDrag = false;
                if (currentOrder() !== startOrder) {
                    saveOrder();
                }
            }

            // Mouse: native HTML5 drag, started only from the handle
            grid.addEventListener('mousedown', function(e) {
                const handle = e.target.closest('.gallery-drag-handle');
                const card = handle ? handle.closest('.gallery-card') : null;
                if (card) {
                    card.setAttribute('draggable', 'true');
                }
            });
            document.addEventListener('mouseup', function() {
                if (!dragCard) {
                    allCards().forEach(function(c) {
                        c.removeAttribute('draggable');
                    });
                }
            });
            grid.addEventListener('dragstart', function(e) {
                const card = e.target.closest ? e.target.closest('.gallery-card') : null;
                if (!card || card.getAttribute('draggable') !== 'true') {
                    return;
                }
                dragCard = card;
                startOrder = currentOrder();
                e.dataTransfer.effectAllowed = 'move';
                try {
                    e.dataTransfer.setData('text/plain', card.dataset.id);
                } catch (err) {}
                setTimeout(function() {
                    if (dragCard) {
                        dragCard.classList.add('is-dragging');
                    }
                }, 0);
            });
            grid.addEventListener('dragover', function(e) {
                if (!dragCard) {
                    return;
                }
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                placeNear(e.target.closest('.gallery-card'), e.clientX, e.clientY);
            });
            grid.addEventListener('drop', function(e) {
                if (dragCard) {
                    e.preventDefault();
                }
            });
            grid.addEventListener('dragend', finishDrag);

            // Touch and pen: pointer events on the handle
            grid.addEventListener('pointerdown', function(e) {
                if (e.pointerType === 'mouse') {
                    return;
                }
                const handle = e.target.closest('.gallery-drag-handle');
                const card = handle ? handle.closest('.gallery-card') : null;
                if (!card) {
                    return;
                }
                e.preventDefault();
                dragCard = card;
                touchDrag = true;
                startOrder = currentOrder();
                card.classList.add('is-dragging');
                try {
                    handle.setPointerCapture(e.pointerId);
                } catch (err) {}
            });
            grid.addEventListener('pointermove', function(e) {
                if (!touchDrag || !dragCard) {
                    return;
                }
                e.preventDefault();
                const under = document.elementFromPoint(e.clientX, e.clientY);
                placeNear(under ? under.closest('.gallery-card') : null, e.clientX, e.clientY);
                if (e.clientY < 70) {
                    window.scrollBy(0, -14);
                } else if (e.clientY > window.innerHeight - 70) {
                    window.scrollBy(0, 14);
                }
            });
            grid.addEventListener('pointerup', function() {
                if (touchDrag) {
                    finishDrag();
                }
            });
            grid.addEventListener('pointercancel', function() {
                if (touchDrag) {
                    finishDrag();
                }
            });

            // Keyboard / button fallback
            grid.addEventListener('click', function(e) {
                const btn = e.target.closest('.gallery-move-btn');
                if (!btn) {
                    return;
                }
                const card = btn.closest('.gallery-card');
                const dir = parseInt(btn.dataset.move, 10);
                const shown = allCards().filter(function(c) {
                    return c.style.display !== 'none';
                });
                const idx = shown.indexOf(card);
                const other = shown[idx + dir];
                if (!other) {
                    return;
                }
                if (dir < 0) {
                    grid.insertBefore(card, other);
                } else {
                    grid.insertBefore(card, other.nextElementSibling);
                }
                btn.focus();
                saveOrder();
            });
        })();

        function filterGallery(category, btn) {
            document.querySelectorAll('.filter-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            document.querySelectorAll('.gallery-card').forEach(card => {
                if (category === 'all' || card.dataset.category === category) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

