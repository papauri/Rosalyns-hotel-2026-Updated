<?php

/**
 * Unified Media Management Portal
 * Centralized ordered catalog for all hotel media assets.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
require_once '../includes/alert.php';

$message = '';
$error = '';

function mm_resolve_preview_url(?string $mediaUrl): string
{
    $value = trim((string)$mediaUrl);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $value)) {
        return $value;
    }
    return '../' . ltrim($value, '/');
}

function mm_store_uploaded_media(array $fileInput, string $mediaType): array
{
    if (!isset($fileInput['error']) || (int)$fileInput['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Please upload a valid media file.');
    }

    $tmp = $fileInput['tmp_name'] ?? '';
    $orig = $fileInput['name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('Uploaded file was not received correctly.');
    }

    $detected = mime_content_type($tmp) ?: '';
    $isImage = stripos($detected, 'image/') === 0;
    $isVideo = stripos($detected, 'video/') === 0;

    if (($mediaType === 'image' && !$isImage) || ($mediaType === 'video' && !$isVideo)) {
        throw new RuntimeException('Uploaded file does not match selected media type.');
    }

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    if ($ext === '') {
        $ext = ($mediaType === 'video') ? 'mp4' : 'jpg';
    }

    $dir = ($mediaType === 'video') ? (__DIR__ . '/../videos/managed/') : (__DIR__ . '/../images/managed/');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create upload directory.');
    }

    $name = 'media_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $dest = $dir . $name;
    if (!move_uploaded_file($tmp, $dest)) {
        throw new RuntimeException('Failed to save uploaded file.');
    }

    return [
        'media_url' => (($mediaType === 'video') ? 'videos/managed/' : 'images/managed/') . $name,
        'mime_type' => $detected,
        'source_type' => 'upload',
    ];
}

function mm_get_allowed_source_columns(): array
{
    return [
        'about_us' => ['image_url'],
        'conference_rooms' => ['image_path'],
        'events' => ['image_path', 'video_path'],
        'rooms' => ['image_url', 'video_path'],
        'gallery' => ['image_url'],
        'page_heroes' => ['hero_image_path', 'hero_video_path'],
        'hotel_gallery' => ['image_url', 'video_path'],
        'restaurant_gallery' => ['image_path'],
        'gym_content' => ['hero_image_path', 'wellness_image_path', 'personal_training_image_path'],
        'welcome' => ['image_path'],
        'testimonials' => ['guest_image'],
        'individual_room_photos' => ['image_path'],
        'site_settings' => ['setting_value'],
    ];
}

/**
 * Resolve the single module a media item belongs to, or null when it is global
 * chrome (home, about, contact, main gallery) that every preset shows.
 * Source-table attribution is most reliable; page_slug is the fallback for hero
 * images (which are stored against page_heroes but tagged with the front page).
 */
function mm_item_module_key(array $item): ?string
{
    $tableModule = [
        'restaurant_gallery'     => 'restaurant_page',
        'gym_content'            => 'gym',
        'conference_rooms'       => 'conference',
        'rooms'                  => 'bookings',
        'individual_room_photos' => 'bookings',
        'hotel_gallery'          => 'bookings',
        'events'                 => 'events',
    ];

    foreach (explode(' | ', (string)($item['source_links'] ?? '')) as $lp) {
        $tbl = strtolower(trim((string)(explode(':', $lp)[0] ?? '')));
        if ($tbl !== '' && isset($tableModule[$tbl])) {
            return $tableModule[$tbl];
        }
    }

    $slug = strtolower(trim((string)($item['page_slug'] ?? '')));
    if ($slug !== '') {
        // Ordered so the more specific page wins (e.g. "conference" before the
        // generic "room" needle). Each preset-owned front page maps to its module.
        $slugRules = [
            'restaurant_page' => ['restaurant', 'dining', 'menu', 'bar'],
            'gym'             => ['gym', 'fitness', 'wellness'],
            'conference'      => ['conference', 'meeting'],
            'events'          => ['event'],
            'bookings'        => ['room', 'accommodation', 'suite'],
        ];
        foreach ($slugRules as $module => $needles) {
            foreach ($needles as $needle) {
                if (strpos($slug, $needle) !== false) {
                    return $module;
                }
            }
        }
    }

    return null; // global — visible on every preset
}

/**
 * A media item is shown only when the module that owns its page is enabled for
 * this installation's preset. Global chrome (module key null) is always shown.
 */
function mm_item_visible_for_preset(array $item): bool
{
    $module = mm_item_module_key($item);
    if ($module === null) {
        return true;
    }
    if (!function_exists('rh_module_key_enabled')) {
        return true;
    }
    return rh_module_key_enabled($module);
}

function mm_sync_page_hero_media(PDO $pdo): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    try {
        $stmt = $pdo->query("SELECT id, page_slug, hero_title, hero_image_path, hero_video_path FROM page_heroes WHERE is_active = 1");
        $heroes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }

    foreach ($heroes as $hero) {
        $heroId = (int)($hero['id'] ?? 0);
        if ($heroId <= 0) {
            continue;
        }

        $pageSlug = trim((string)($hero['page_slug'] ?? ''));
        $heroTitle = trim((string)($hero['hero_title'] ?? '')) ?: 'Page Hero';

        $heroImagePath = trim((string)($hero['hero_image_path'] ?? ''));
        if ($heroImagePath !== '') {
            upsertManagedMediaForSource('page_heroes', $heroId, 'hero_image_path', $heroImagePath, [
                'title' => $heroTitle . ' (Hero Image)',
                'placement_key' => 'page_hero.' . $pageSlug . '.image',
                'page_slug' => $pageSlug,
                'section_key' => 'hero',
                'entity_type' => 'page_hero',
                'entity_id' => $heroId,
                'source_context' => 'page_hero_media',
                'display_order' => 0,
            ]);
        }

        $heroVideoPath = trim((string)($hero['hero_video_path'] ?? ''));
        if ($heroVideoPath !== '') {
            upsertManagedMediaForSource('page_heroes', $heroId, 'hero_video_path', $heroVideoPath, [
                'title' => $heroTitle . ' (Hero Video)',
                'placement_key' => 'page_hero.' . $pageSlug . '.video',
                'page_slug' => $pageSlug,
                'section_key' => 'hero',
                'entity_type' => 'page_hero',
                'entity_id' => $heroId,
                'source_context' => 'page_hero_media',
                'display_order' => 0,
            ]);
        }
    }
}

function mm_sync_about_us_media(PDO $pdo): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    try {
        $stmt = $pdo->query("SELECT id, title, image_url FROM about_us WHERE section_type = 'main' AND is_active = 1 AND image_url IS NOT NULL AND image_url != '' ORDER BY display_order ASC, id ASC");
        $aboutItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return;
    }

    foreach ($aboutItems as $about) {
        $aboutId = (int)($about['id'] ?? 0);
        if ($aboutId <= 0) {
            continue;
        }

        $aboutTitle = trim((string)($about['title'] ?? '')) ?: 'About Us Section';
        $aboutImageUrl = trim((string)($about['image_url'] ?? ''));

        if ($aboutImageUrl !== '') {
            upsertManagedMediaForSource('about_us', $aboutId, 'image_url', $aboutImageUrl, [
                'title' => $aboutTitle . ' (About Us Image)',
                'placement_key' => 'about_us.main.image',
                'section_key' => 'about',
                'entity_type' => 'about_us',
                'entity_id' => $aboutId,
                'source_context' => 'about_us_media',
                'display_order' => 0,
            ]);
        }
    }
}

function mm_propagate_media_to_sources(PDO $pdo, int $catalogId, ?string $mediaUrl, bool $deleteMode = false): void
{
    $linksStmt = $pdo->prepare("SELECT source_table, source_record_id, source_column, source_context FROM managed_media_links WHERE media_catalog_id = ?");
    $linksStmt->execute([$catalogId]);
    $links = $linksStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (empty($links)) {
        return;
    }

    $allow = mm_get_allowed_source_columns();

    foreach ($links as $link) {
        $table = $link['source_table'] ?? '';
        $column = $link['source_column'] ?? '';
        $recordId = (string)($link['source_record_id'] ?? '');

        if ($table === '' || $column === '' || $recordId === '') {
            continue;
        }

        if (!isset($allow[$table]) || !in_array($column, $allow[$table], true)) {
            continue;
        }

        if ($table === 'site_settings') {
            $update = $pdo->prepare("UPDATE site_settings SET setting_value = ? WHERE id = ?");
            $update->execute([$deleteMode ? '' : (string)$mediaUrl, (int)$recordId]);
            continue;
        }

        if ($table === 'page_heroes') {
            if (ctype_digit($recordId)) {
                $sql = "UPDATE page_heroes SET `{$column}` = ? WHERE id = ?";
                $update = $pdo->prepare($sql);
                $update->execute([$deleteMode ? null : $mediaUrl, (int)$recordId]);
            } else {
                $sql = "UPDATE page_heroes SET `{$column}` = ? WHERE page_slug = ?";
                $update = $pdo->prepare($sql);
                $update->execute([$deleteMode ? null : $mediaUrl, $recordId]);
            }
            continue;
        }

        $sql = "UPDATE `{$table}` SET `{$column}` = ? WHERE id = ?";
        $update = $pdo->prepare($sql);
        $update->execute([$deleteMode ? null : $mediaUrl, ctype_digit($recordId) ? (int)$recordId : $recordId]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        try {
            if ($action === 'create_item') {
                if (!hasPermission($user['id'], 'media_create')) {
                    throw new RuntimeException('You do not have permission to create media items.');
                }

                $title = trim((string)($_POST['title'] ?? ''));
                $mediaType = (($_POST['media_type'] ?? 'image') === 'video') ? 'video' : 'image';

                if ($title === '') {
                    throw new RuntimeException('Title is required.');
                }

                $mediaUrl   = null;
                $mimeType   = null;
                $sourceType = 'upload';

                $urlInput = trim((string)($_POST['media_url_input'] ?? ''));
                $hasFile  = isset($_FILES['media_file']) && (int)($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

                if ($urlInput !== '') {
                    $mediaUrl   = $urlInput;
                    $sourceType = 'url';
                    $mimeType   = ($mediaType === 'video') ? 'url' : null;
                } elseif ($hasFile) {
                    $uploaded   = mm_store_uploaded_media($_FILES['media_file'], $mediaType);
                    $mediaUrl   = $uploaded['media_url'];
                    $mimeType   = $uploaded['mime_type'];
                    $sourceType = 'upload';
                } else {
                    throw new RuntimeException('Please upload a file or enter a URL.');
                }

                $stmt = $pdo->prepare("INSERT INTO managed_media_catalog (title, description, media_type, source_type, media_url, mime_type, alt_text, caption, placement_key, page_slug, section_key, entity_type, entity_id, is_active, display_order, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $title,
                    trim((string)($_POST['description'] ?? '')) ?: null,
                    $mediaType,
                    $sourceType,
                    $mediaUrl,
                    $mimeType,
                    trim((string)($_POST['alt_text'] ?? '')) ?: null,
                    trim((string)($_POST['caption'] ?? '')) ?: null,
                    trim((string)($_POST['placement_key'] ?? '')) ?: null,
                    trim((string)($_POST['page_slug'] ?? '')) ?: null,
                    trim((string)($_POST['section_key'] ?? '')) ?: null,
                    trim((string)($_POST['entity_type'] ?? '')) ?: null,
                    ($_POST['entity_id'] ?? '') !== '' ? (int)$_POST['entity_id'] : null,
                    isset($_POST['is_active']) ? 1 : 0,
                    (int)($_POST['display_order'] ?? 0),
                    (int)$user['id'],
                ]);

                // Link to source table if requested
                $newCatalogId = (int)$pdo->lastInsertId();
                $linkTable    = trim((string)($_POST['link_source_table'] ?? ''));
                $linkColumn   = trim((string)($_POST['link_source_column'] ?? ''));
                $linkRecId    = trim((string)($_POST['link_source_record_id'] ?? ''));
                $linked       = false;
                if ($linkTable !== '' && $linkColumn !== '' && $linkRecId !== '') {
                    $allowCols = mm_get_allowed_source_columns();
                    if (isset($allowCols[$linkTable]) && in_array($linkColumn, $allowCols[$linkTable], true)) {
                        $linkStmt = $pdo->prepare("INSERT INTO managed_media_links (media_catalog_id, source_table, source_record_id, source_column, source_context, media_type, page_slug, section_key, entity_type, entity_id, use_case, display_order, is_active) VALUES (?, ?, ?, ?, 'manual', ?, ?, ?, ?, ?, 'manual_link', 0, 1)");
                        $linkStmt->execute([
                            $newCatalogId,
                            $linkTable,
                            $linkRecId,
                            $linkColumn,
                            $mediaType,
                            trim((string)($_POST['page_slug'] ?? '')) ?: null,
                            trim((string)($_POST['section_key'] ?? '')) ?: null,
                            trim((string)($_POST['entity_type'] ?? '')) ?: null,
                            ($_POST['entity_id'] ?? '') !== '' ? (int)$_POST['entity_id'] : null,
                        ]);
                        mm_propagate_media_to_sources($pdo, $newCatalogId, $mediaUrl, false);
                        if (function_exists('invalidateDataCaches')) {
                            invalidateDataCaches();
                        }
                        $linked = true;
                    }
                }
                $message = $linked
                    ? 'Media item created and pushed to ' . $linkTable . ' → ' . $linkColumn . '.'
                    : 'Media item created.';
            }

            if ($action === 'update_item') {
                if (!hasPermission($user['id'], 'media_edit')) {
                    throw new RuntimeException('You do not have permission to update media items.');
                }

                $itemId = (int)($_POST['item_id'] ?? 0);
                if ($itemId <= 0) {
                    throw new RuntimeException('Invalid media item.');
                }

                $currentStmt = $pdo->prepare("SELECT id, source_type, media_url, mime_type FROM managed_media_catalog WHERE id = ?");
                $currentStmt->execute([$itemId]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);
                if (!$current) {
                    throw new RuntimeException('Media item not found.');
                }

                $mediaType = (($_POST['media_type'] ?? 'image') === 'video') ? 'video' : 'image';
                $newMediaUrl = $current['media_url'];
                $newMimeType = $current['mime_type'];
                $newSourceType = $current['source_type'];

                $urlReplacement = trim((string)($_POST['media_url_input'] ?? ''));
                $hasFileReplacement = isset($_FILES['media_file']) && (int)($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

                if ($urlReplacement !== '') {
                    $newMediaUrl = $urlReplacement;
                    $newSourceType = 'url';
                    $newMimeType = ($mediaType === 'video') ? 'url' : null;
                } elseif ($hasFileReplacement) {
                    $uploaded = mm_store_uploaded_media($_FILES['media_file'], $mediaType);
                    $newMediaUrl = $uploaded['media_url'];
                    $newMimeType = $uploaded['mime_type'];
                    $newSourceType = 'upload';

                    if (($current['source_type'] ?? '') === 'upload' && !empty($current['media_url'])) {
                        $oldPath = __DIR__ . '/../' . ltrim($current['media_url'], '/');
                        if (is_file($oldPath)) {
                            @unlink($oldPath);
                        }
                    }
                }

                $stmt = $pdo->prepare("UPDATE managed_media_catalog SET title = ?, description = ?, media_type = ?, source_type = ?, media_url = ?, mime_type = ?, alt_text = ?, caption = ?, placement_key = ?, page_slug = ?, section_key = ?, entity_type = ?, entity_id = ?, is_active = ?, display_order = ? WHERE id = ?");
                $stmt->execute([
                    trim((string)($_POST['title'] ?? '')),
                    trim((string)($_POST['description'] ?? '')) ?: null,
                    $mediaType,
                    $newSourceType,
                    $newMediaUrl,
                    $newMimeType,
                    trim((string)($_POST['alt_text'] ?? '')) ?: null,
                    trim((string)($_POST['caption'] ?? '')) ?: null,
                    trim((string)($_POST['placement_key'] ?? '')) ?: null,
                    trim((string)($_POST['page_slug'] ?? '')) ?: null,
                    trim((string)($_POST['section_key'] ?? '')) ?: null,
                    trim((string)($_POST['entity_type'] ?? '')) ?: null,
                    ($_POST['entity_id'] ?? '') !== '' ? (int)$_POST['entity_id'] : null,
                    isset($_POST['is_active']) ? 1 : 0,
                    (int)($_POST['display_order'] ?? 0),
                    $itemId,
                ]);

                mm_propagate_media_to_sources($pdo, $itemId, $newMediaUrl, false);

                if (function_exists('invalidateDataCaches')) {
                    invalidateDataCaches();
                }

                $message = 'Media item updated.';
            }

            if ($action === 'delete_item') {
                if (!hasPermission($user['id'], 'media_delete')) {
                    throw new RuntimeException('You do not have permission to delete media items.');
                }

                $itemId = (int)($_POST['item_id'] ?? 0);
                if ($itemId <= 0) {
                    throw new RuntimeException('Invalid media item.');
                }

                $currentStmt = $pdo->prepare("SELECT source_type, media_url FROM managed_media_catalog WHERE id = ?");
                $currentStmt->execute([$itemId]);
                $current = $currentStmt->fetch(PDO::FETCH_ASSOC);

                mm_propagate_media_to_sources($pdo, $itemId, null, true);

                if (function_exists('invalidateDataCaches')) {
                    invalidateDataCaches();
                }

                $stmt = $pdo->prepare("DELETE FROM managed_media_catalog WHERE id = ?");
                $stmt->execute([$itemId]);

                if ($current && ($current['source_type'] ?? '') === 'upload' && !empty($current['media_url'])) {
                    $fullPath = __DIR__ . '/../' . ltrim($current['media_url'], '/');
                    if (is_file($fullPath)) {
                        @unlink($fullPath);
                    }
                }

                $message = 'Media item deleted.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$mediaItems = [];
try {
    mm_sync_page_hero_media($pdo);
    mm_sync_about_us_media($pdo);
    $stmt = $pdo->query("SELECT c.*, GROUP_CONCAT(CONCAT(l.source_table, ':', l.source_column, ':', l.source_record_id, IF(l.source_context IS NULL OR l.source_context = '', '', CONCAT(':', l.source_context))) ORDER BY l.source_table, l.source_column, l.source_record_id SEPARATOR ' | ') AS source_links FROM managed_media_catalog c LEFT JOIN managed_media_links l ON l.media_catalog_id = c.id WHERE c.is_active = 1 AND c.media_url IS NOT NULL AND TRIM(c.media_url) <> '' GROUP BY c.id ORDER BY COALESCE(c.page_slug, ''), COALESCE(c.section_key, ''), c.display_order ASC, c.id ASC");
    $mediaItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Unable to load unified media catalog. Run Database/migrations/011_unified_media_catalog.sql first. ' . $e->getMessage();
}

$media_css_version = (string)@filemtime(__DIR__ . '/css/media-management.css');
if ($media_css_version === '' || $media_css_version === '0') {
    $media_css_version = (string)time();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Media Portal - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/media-management.css?v=<?php echo urlencode($media_css_version); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <div class="content">

        <div class="page-header">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
                <div>
                    <h2 class="page-title"><i class="fas fa-photo-video"></i> Media Portal</h2>
                    <p class="text-muted">All hotel images and videos — hero banners, gallery photos, room pictures — in one place.</p>
                </div>
                <?php if (hasPermission($user['id'], 'media_create')): ?>
                    <button type="button" class="btn-primary" onclick="mmToggleCreate()" id="mm-add-btn"
                        style="display:inline-flex;align-items:center;gap:8px;">
                        <i class="fas fa-plus"></i> Add Media
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- How this works -->
        <div class="card" style="margin-bottom:20px;border-left:4px solid var(--gold);background:#fffdf8;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px 24px;padding:4px 0;">
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <i class="fas fa-upload" style="color:var(--gold);font-size:20px;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <strong style="display:block;color:var(--navy);margin-bottom:2px;">Upload or link</strong>
                        <span style="font-size:13px;color:#666;">Upload a file from your computer, or paste a direct URL to an image or video already online.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <i class="fas fa-link" style="color:var(--gold);font-size:20px;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <strong style="display:block;color:var(--navy);margin-bottom:2px;">Linked to the website</strong>
                        <span style="font-size:13px;color:#666;">Each item is tagged to a page (e.g. <em>index</em>) and section (e.g. <em>hero</em>). Saving it updates that section on the live website automatically.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <i class="fas fa-sync-alt" style="color:var(--gold);font-size:20px;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <strong style="display:block;color:var(--navy);margin-bottom:2px;">Hero banners auto-sync</strong>
                        <span style="font-size:13px;color:#666;">Page hero images set in <a href="section-headers-management.php" style="color:var(--gold);">Section Headers</a> appear here automatically so everything is in one catalog.</span>
                    </div>
                </div>
                <div style="display:flex;gap:12px;align-items:flex-start;">
                    <i class="fas fa-edit" style="color:var(--gold);font-size:20px;margin-top:2px;flex-shrink:0;"></i>
                    <div>
                        <strong style="display:block;color:var(--navy);margin-bottom:2px;">Edit anytime</strong>
                        <span style="font-size:13px;color:#666;">Click <strong>Edit</strong> on any card to swap the image/video, update alt text for SEO, or change display order.</span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($message): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
        <?php if ($error): ?><?php showAlert($error, 'error'); ?><?php endif; ?>

        <?php if (hasPermission($user['id'], 'media_create')): ?>
            <!-- Create form — collapsed by default -->
            <div id="mm-create-panel" class="card mm-create-panel" style="display:none;margin-bottom:24px;">
                <h3 class="mm-panel-title"><i class="fas fa-plus-circle"></i> Add New Media Item</h3>
                <p style="margin:-4px 0 16px;font-size:13px;color:#666;">Fill in the title, tag it to a page and section, then either upload a file or paste a URL. Saving will push the image/video to that section on the website.</p>
                <form method="POST" enctype="multipart/form-data" id="mm-create-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="create_item">
                    <div class="mm-form-grid">
                        <div class="mm-field mm-field--wide">
                            <label class="mm-label">Title <span class="mm-required">*</span></label>
                            <input type="text" name="title" required placeholder="e.g., Homepage Hero Background" class="mm-input">
                        </div>
                        <div class="mm-field">
                            <label class="mm-label">Page <span style="font-weight:400;color:#888;">(which page shows this?)</span></label>
                            <input type="text" name="page_slug" placeholder="e.g., index, restaurant, rooms-gallery" class="mm-input" list="mm-page-slugs">
                        </div>
                        <div class="mm-field">
                            <label class="mm-label">Section <span style="font-weight:400;color:#888;">(where on the page?)</span></label>
                            <input type="text" name="section_key" placeholder="e.g., hero, gallery, about" class="mm-input">
                        </div>
                        <div class="mm-field">
                            <label class="mm-label">Media Type</label>
                            <select name="media_type" class="mm-input">
                                <option value="image">Image</option>
                                <option value="video">Video</option>
                            </select>
                        </div>
                        <div class="mm-field">
                            <label class="mm-label">Display Order</label>
                            <input type="number" name="display_order" value="0" min="0" class="mm-input">
                        </div>
                        <div class="mm-field mm-field--wide">
                            <label class="mm-label">Upload File <span style="font-weight:400;color:#888;">— from your computer</span></label>
                            <input type="file" name="media_file" accept="image/*,video/*" class="mm-input">
                        </div>
                        <div class="mm-field mm-field--wide">
                            <label class="mm-label">Or External URL <span style="font-weight:400;color:#888;">— direct link to image/video online</span></label>
                            <input type="url" name="media_url_input" placeholder="https://... (leave blank if uploading a file above)" class="mm-input">
                        </div>
                        <div class="mm-field">
                            <label class="mm-label">Alt Text <span style="font-weight:400;color:#888;">— for SEO &amp; accessibility</span></label>
                            <input type="text" name="alt_text" placeholder="Describe what's in the image" class="mm-input">
                        </div>
                        <div class="mm-field">
                            <label class="mm-label">Caption</label>
                            <input type="text" name="caption" placeholder="Optional caption" class="mm-input">
                        </div>
                        <div class="mm-field mm-field--full">
                            <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer;font-weight:600;">
                                <input type="checkbox" name="is_active" checked style="width:16px;height:16px;"> Active
                            </label>
                        </div>
                    </div>
                    <!-- Push to source record (optional) -->
                    <details class="mm-link-details" style="margin-top:16px;border:1px solid #e8e0d8;border-radius:8px;padding:0;">
                        <summary style="cursor:pointer;font-weight:600;color:var(--navy);font-size:14px;padding:12px 16px;user-select:none;list-style:none;display:flex;align-items:center;gap:8px;">
                            <i class="fas fa-plug" style="color:var(--gold);"></i>
                            Push to a website record <span style="font-weight:400;color:#888;font-size:13px;">(optional — updates the actual page on the website)</span>
                            <i class="fas fa-chevron-down mm-details-chevron" style="margin-left:auto;font-size:12px;opacity:.5;"></i>
                        </summary>
                        <div style="padding:16px;border-top:1px solid #e8e0d8;">
                            <p style="margin:0 0 12px;font-size:13px;color:#666;">Choose which table row this image should update. When saved, the column you select will be set to this image path automatically.</p>
                            <div class="mm-form-grid">
                                <div class="mm-field">
                                    <label class="mm-label">Source Table</label>
                                    <select name="link_source_table" class="mm-input" id="mm-link-table-create">
                                        <option value="">— None —</option>
                                        <?php foreach (array_keys(mm_get_allowed_source_columns()) as $tbl): ?>
                                            <?php // Hide source tables whose module is off for this preset.
                                            if (!mm_item_visible_for_preset(['source_links' => $tbl . '::'])) { continue; } ?>
                                            <option value="<?= htmlspecialchars($tbl) ?>"><?= htmlspecialchars($tbl) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Column <span style="font-weight:400;color:#888;">(field to update)</span></label>
                                    <input type="text" name="link_source_column" placeholder="e.g. hero_image_path" class="mm-input" list="mm-column-hints-create" id="mm-link-col-create">
                                    <datalist id="mm-column-hints-create"></datalist>
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Record ID <span style="font-weight:400;color:#888;">(row id in that table)</span></label>
                                    <input type="text" name="link_source_record_id" placeholder="e.g. 1" class="mm-input">
                                </div>
                            </div>
                        </div>
                    </details>
                    <div style="display:flex;gap:10px;margin-top:12px;">
                        <button type="submit" class="btn-primary" style="display:inline-flex;align-items:center;gap:8px;">
                            <i class="fas fa-plus"></i> Add Item
                        </button>
                        <button type="button" onclick="mmToggleCreate()" class="btn-secondary">Cancel</button>
                    </div>
                </form>
            </div>
        <?php endif; ?>

        <!-- Filter bar -->
        <?php
        $displayItems = array_values(array_filter($mediaItems, static function ($item) {
            $itemId = (int)($item['id'] ?? 0);
            $mediaUrl = trim((string)($item['media_url'] ?? ''));
            $isActive = (int)($item['is_active'] ?? 0) === 1;

            // Only show media whose owning page/module is active for this preset —
            // a gym install shouldn't see restaurant or conference imagery.
            return $itemId > 0 && $mediaUrl !== '' && $isActive && mm_item_visible_for_preset($item);
        }));

        $allSlugs = array_filter(array_unique(array_map(static function ($item) {
            return trim((string)($item['page_slug'] ?? ''));
        }, $displayItems)));
        sort($allSlugs);
        $filterSlug = $_GET['filter_slug'] ?? '';
        $filterType = $_GET['filter_type'] ?? '';
        $filteredItems = array_values(array_filter($displayItems, static function ($item) use ($filterSlug, $filterType) {
            if ($filterSlug !== '' && ($item['page_slug'] ?? '') !== $filterSlug) return false;
            if ($filterType !== '' && ($item['media_type'] ?? '') !== $filterType) return false;
            return true;
        }));

        $modalItems = [];
        foreach ($displayItems as $item) {
            $itemId = (int)($item['id'] ?? 0);
            if ($itemId <= 0) {
                continue;
            }

            $itemTitle = trim((string)($item['title'] ?? ''));
            if ($itemTitle === '') {
                $itemTitle = 'Untitled media item';
            }

            $modalItems[(string)$itemId] = [
                'id' => $itemId,
                'title' => $itemTitle,
                'description' => (string)($item['description'] ?? ''),
                'media_type' => (($item['media_type'] ?? '') === 'video') ? 'video' : 'image',
                'media_url' => (string)($item['media_url'] ?? ''),
                'page_slug' => (string)($item['page_slug'] ?? ''),
                'section_key' => (string)($item['section_key'] ?? ''),
                'alt_text' => (string)($item['alt_text'] ?? ''),
                'caption' => (string)($item['caption'] ?? ''),
                'placement_key' => (string)($item['placement_key'] ?? ''),
                'entity_type' => (string)($item['entity_type'] ?? ''),
                'entity_id' => ($item['entity_id'] ?? '') !== '' ? (int)$item['entity_id'] : '',
                'display_order' => (int)($item['display_order'] ?? 0),
                'is_active' => (int)($item['is_active'] ?? 0) === 1,
                'preview_url' => mm_resolve_preview_url($item['media_url'] ?? ''),
            ];
        }
        $modalItemsJson = json_encode($modalItems, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if (!is_string($modalItemsJson)) {
            $modalItemsJson = '{}';
        }
        ?>
        <!-- Shared datalist for page slugs (used by create + edit forms) -->
        <datalist id="mm-page-slugs">
            <?php foreach ($allSlugs as $slug): ?>
                <option value="<?= htmlspecialchars($slug) ?>">
                <?php endforeach; ?>
        </datalist>
        <div class="mm-filter-bar">
            <form method="GET" style="display:flex;flex-wrap:wrap;align-items:center;gap:10px;width:100%;">
                <label style="font-weight:600;color:var(--navy);font-size:14px;"><i class="fas fa-filter"></i> Filter by page or type:</label>
                <select name="filter_slug" onchange="this.form.submit()" class="mm-input" style="width:auto;min-width:140px;padding:7px 10px;">
                    <option value="">All Pages</option>
                    <?php foreach ($allSlugs as $slug): ?>
                        <option value="<?= htmlspecialchars($slug) ?>" <?= $filterSlug === $slug ? 'selected' : '' ?>>
                            <?= htmlspecialchars($slug) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <select name="filter_type" onchange="this.form.submit()" class="mm-input" style="width:auto;min-width:120px;padding:7px 10px;">
                    <option value="">All Types</option>
                    <option value="image" <?= $filterType === 'image' ? 'selected' : '' ?>>Images</option>
                    <option value="video" <?= $filterType === 'video' ? 'selected' : '' ?>>Videos</option>
                </select>
                <?php if ($filterSlug !== '' || $filterType !== ''): ?>
                    <a href="media-management.php" class="btn-secondary" style="padding:7px 14px;font-size:13px;text-decoration:none;">
                        <i class="fas fa-times"></i> Clear
                    </a>
                <?php endif; ?>
                <span style="margin-left:auto;font-size:13px;color:#888;">
                    <?= count($filteredItems) ?> of <?= count($displayItems) ?> items
                </span>
            </form>
        </div>

        <!-- Media cards -->
        <?php if (empty($filteredItems)): ?>
            <div class="card" style="text-align:center;padding:48px 20px;color:#888;">
                <i class="fas fa-photo-video" style="font-size:48px;margin-bottom:12px;opacity:.3;display:block;"></i>
                <?php if (!empty($error)): ?>
                    <p>Media catalog table not found. Run the migration first.</p>
                <?php else: ?>
                    <p>No media items found<?= ($filterSlug || $filterType) ? ' for this filter' : '' ?>.</p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="mm-catalog">
                <?php foreach ($filteredItems as $item):
                    $itemId = (int)($item['id'] ?? 0);
                    if ($itemId <= 0) {
                        continue;
                    }

                    $itemTitle = trim((string)($item['title'] ?? ''));
                    if ($itemTitle === '') {
                        $itemTitle = 'Untitled media item';
                    }

                    $preview = mm_resolve_preview_url($item['media_url'] ?? '');
                    $isVideo = ($item['media_type'] ?? '') === 'video';
                    $tags = array_filter([
                        $item['page_slug'] ?? '',
                        $item['section_key'] ?? '',
                    ]);
                ?>
                    <div class="mm-card" id="card_<?= $itemId ?>">
                        <!-- Thumbnail -->
                        <div class="mm-card__thumb">
                            <?php if ($isVideo && $preview !== ''): ?>
                                <a href="<?= htmlspecialchars($preview) ?>" target="_blank" rel="noopener" class="mm-thumb-link" title="Open video in new tab">
                                    <video src="<?= htmlspecialchars($preview) ?>" class="mm-thumb-media" muted preload="metadata"></video>
                                    <span class="mm-badge mm-badge--video"><i class="fas fa-video"></i></span>
                                    <span class="mm-thumb-overlay"><i class="fas fa-external-link-alt"></i></span>
                                </a>
                            <?php elseif ($preview !== ''): ?>
                                <a href="<?= htmlspecialchars($preview) ?>" target="_blank" rel="noopener" class="mm-thumb-link" title="Open image in new tab">
                                    <img src="<?= htmlspecialchars($preview) ?>" alt="<?= htmlspecialchars((string)($item['alt_text'] ?: $itemTitle)) ?>" class="mm-thumb-media" loading="lazy">
                                    <span class="mm-thumb-overlay"><i class="fas fa-external-link-alt"></i></span>
                                </a>
                            <?php else: ?>
                                <div class="mm-thumb-placeholder"><i class="fas fa-image"></i></div>
                            <?php endif; ?>
                        </div>

                        <!-- Info -->
                        <div class="mm-card__body">
                            <div class="mm-card__title"><?= htmlspecialchars($itemTitle) ?></div>
                            <div class="mm-card__meta">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="mm-tag"><?= htmlspecialchars($tag) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <div class="mm-card__url" title="<?= htmlspecialchars((string)($item['media_url'] ?? '')) ?>">
                                <?php if (($item['media_url'] ?? '') !== ''): ?>
                                    <i class="fas fa-link mm-card__url-icon"></i><?= htmlspecialchars((string)$item['media_url']) ?>
                                <?php else: ?>
                                    <span class="mm-card__url-empty">No file set yet</span>
                                <?php endif; ?>
                            </div>
                            <div class="mm-card__order">Display order: <?= (int)($item['display_order'] ?? 0) ?></div>
                            <div class="mm-card__links <?= empty($item['source_links']) ? 'mm-card__links--empty' : '' ?>">
                                <?php if (!empty($item['source_links'])): ?>
                                    <i class="fas fa-plug mm-card__links-icon"></i>
                                    <?php
                                    $linkParts = explode(' | ', (string)$item['source_links']);
                                    foreach ($linkParts as $lp):
                                        $lpParts   = explode(':', $lp);
                                        $lpTable   = $lpParts[0] ?? '';
                                        $lpColumn  = $lpParts[1] ?? '';
                                        $lpRecId   = $lpParts[2] ?? '';
                                        $lpLabel   = $lpTable . '.' . $lpColumn . ($lpRecId !== '' ? ' #' . $lpRecId : '');
                                    ?>
                                        <span class="mm-tag mm-tag--link" title="Linked to <?= htmlspecialchars($lp) ?>"><?= htmlspecialchars($lpLabel) ?></span>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span class="mm-card__links-placeholder" aria-hidden="true">No source links</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="mm-card__actions">
                            <?php if (hasPermission($user['id'], 'media_edit')): ?>
                                <button type="button"
                                    onclick="mmOpenEditModal(<?= $itemId ?>)"
                                    class="mm-action-btn mm-action-btn--edit"
                                    aria-label="Edit <?= htmlspecialchars($itemTitle) ?>">
                                    <i class="fas fa-pen"></i>
                                    <span>Edit</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (hasPermission($user['id'], 'media_edit')): ?>
            <div class="modal-overlay" id="mmEditModal" aria-hidden="true">
                <div class="modal-content mm-edit-modal" role="dialog" aria-modal="true" aria-labelledby="mmEditModalTitle">
                    <div class="modal-header">
                        <h3 id="mmEditModalTitle"><i class="fas fa-pen"></i> Edit Media Item</h3>
                        <button class="modal-close" type="button" onclick="mmCloseEditModal()" aria-label="Close edit modal">&times;</button>
                    </div>
                    <form method="POST" enctype="multipart/form-data" id="mm-edit-modal-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                        <input type="hidden" name="action" value="update_item" id="mm-edit-action">
                        <input type="hidden" name="item_id" id="mm-edit-item-id">
                        <input type="hidden" name="placement_key" id="mm-edit-placement-key">
                        <input type="hidden" name="entity_type" id="mm-edit-entity-type">
                        <input type="hidden" name="entity_id" id="mm-edit-entity-id">

                        <div class="mm-edit-modal__body">
                            <div class="mm-edit-preview" id="mm-edit-preview" hidden>
                                <div class="mm-edit-preview__media" id="mm-edit-preview-media"></div>
                                <div class="mm-edit-preview__details">
                                    <p class="mm-edit-preview__label">Current media</p>
                                    <p class="mm-edit-preview__path" id="mm-edit-preview-path"></p>
                                </div>
                            </div>

                            <div class="mm-form-grid">
                                <div class="mm-field mm-field--wide">
                                    <label class="mm-label">Title <span class="mm-required">*</span></label>
                                    <input type="text" id="mm-edit-title" name="title" required class="mm-input">
                                </div>
                                <div class="mm-field mm-field--wide">
                                    <label class="mm-label">Description</label>
                                    <textarea id="mm-edit-description" name="description" rows="2" class="mm-input"></textarea>
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Page Slug</label>
                                    <input type="text" id="mm-edit-page-slug" name="page_slug" placeholder="e.g. index" class="mm-input" list="mm-page-slugs">
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Section Key</label>
                                    <input type="text" id="mm-edit-section-key" name="section_key" placeholder="e.g. hero" class="mm-input">
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Media Type</label>
                                    <select id="mm-edit-media-type" name="media_type" class="mm-input">
                                        <option value="image">Image</option>
                                        <option value="video">Video</option>
                                    </select>
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Display Order</label>
                                    <input type="number" id="mm-edit-display-order" name="display_order" min="0" class="mm-input">
                                </div>
                                <div class="mm-field mm-field--wide">
                                    <label class="mm-label">Replace with new file</label>
                                    <input type="file" id="mm-edit-media-file" name="media_file" accept="image/*,video/*" class="mm-input">
                                </div>
                                <div class="mm-field mm-field--wide">
                                    <label class="mm-label">Or replace with URL</label>
                                    <input type="url" id="mm-edit-media-url" name="media_url_input" placeholder="Leave blank to keep current" class="mm-input">
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Alt Text</label>
                                    <input type="text" id="mm-edit-alt-text" name="alt_text" class="mm-input">
                                </div>
                                <div class="mm-field">
                                    <label class="mm-label">Caption</label>
                                    <input type="text" id="mm-edit-caption" name="caption" class="mm-input">
                                </div>
                                <div class="mm-field mm-field--full">
                                    <label class="mm-edit-active-toggle">
                                        <input type="checkbox" id="mm-edit-is-active" name="is_active" value="1"> Active
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mm-modal-actions">
                            <button type="button" class="mm-modal-btn mm-modal-btn--ghost" onclick="mmCloseEditModal()">Cancel</button>
                            <?php if (hasPermission($user['id'], 'media_delete')): ?>
                                <button type="button" id="mm-modal-delete-btn" class="mm-modal-btn mm-modal-btn--danger">
                                    <i class="fas fa-trash"></i> Delete
                                </button>
                            <?php endif; ?>
                            <button type="submit" class="mm-modal-btn mm-modal-btn--primary">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

    </div><!-- /.content -->

    <script>
        function mmToggleCreate() {
            var panel = document.getElementById('mm-create-panel');
            var btn = document.getElementById('mm-add-btn');
            if (!panel) return;
            var nowHidden = panel.style.display === 'none' || panel.style.display === '';
            panel.style.display = nowHidden ? 'block' : 'none';
            if (nowHidden) {
                panel.scrollIntoView({
                    behavior: 'smooth',
                    block: 'nearest'
                });
                var firstInput = panel.querySelector('input[name="title"]');
                if (firstInput) setTimeout(function() {
                    firstInput.focus();
                }, 200);
            }
        }

        var mmItems = <?= $modalItemsJson ?>;
        var mmCsrfToken = <?= json_encode($csrf_token) ?>;

        function mmRenderEditPreview(item) {
            var previewWrap = document.getElementById('mm-edit-preview');
            var previewMedia = document.getElementById('mm-edit-preview-media');
            var previewPath = document.getElementById('mm-edit-preview-path');

            if (!previewWrap || !previewMedia || !previewPath) {
                return;
            }

            while (previewMedia.firstChild) {
                previewMedia.removeChild(previewMedia.firstChild);
            }

            var mediaUrl = String(item.media_url || '');
            var previewUrl = String(item.preview_url || '');
            previewPath.textContent = mediaUrl || 'No current file path';

            if (previewUrl) {
                if (item.media_type === 'video') {
                    var video = document.createElement('video');
                    video.src = previewUrl;
                    video.muted = true;
                    video.controls = true;
                    video.preload = 'metadata';
                    video.className = 'mm-edit-preview__asset';
                    previewMedia.appendChild(video);
                } else {
                    var image = document.createElement('img');
                    image.src = previewUrl;
                    image.alt = String(item.alt_text || item.title || 'Media preview');
                    image.className = 'mm-edit-preview__asset';
                    image.loading = 'lazy';
                    previewMedia.appendChild(image);
                }
            } else {
                var placeholder = document.createElement('span');
                placeholder.className = 'mm-edit-preview__placeholder';
                placeholder.textContent = 'No preview';
                previewMedia.appendChild(placeholder);
            }

            previewWrap.hidden = false;
        }

        function mmOpenEditModal(itemId) {
            var modal = document.getElementById('mmEditModal');
            var item = mmItems[String(parseInt(itemId, 10))] || null;
            if (!modal || !item) {
                return;
            }

            var setValue = function(id, value) {
                var el = document.getElementById(id);
                if (el) {
                    el.value = value == null ? '' : String(value);
                }
            };

            setValue('mm-edit-item-id', item.id || '');
            setValue('mm-edit-title', item.title || '');
            setValue('mm-edit-description', item.description || '');
            setValue('mm-edit-page-slug', item.page_slug || '');
            setValue('mm-edit-section-key', item.section_key || '');
            setValue('mm-edit-media-type', item.media_type === 'video' ? 'video' : 'image');
            setValue('mm-edit-display-order', item.display_order || 0);
            setValue('mm-edit-alt-text', item.alt_text || '');
            setValue('mm-edit-caption', item.caption || '');
            setValue('mm-edit-placement-key', item.placement_key || '');
            setValue('mm-edit-entity-type', item.entity_type || '');
            setValue('mm-edit-entity-id', item.entity_id || '');
            setValue('mm-edit-media-url', '');

            var isActiveInput = document.getElementById('mm-edit-is-active');
            if (isActiveInput) {
                isActiveInput.checked = !!item.is_active;
            }

            var fileInput = document.getElementById('mm-edit-media-file');
            if (fileInput) {
                fileInput.value = '';
            }

            var deleteBtn = document.getElementById('mm-modal-delete-btn');
            if (deleteBtn) {
                deleteBtn.dataset.itemId = String(item.id || '');
                deleteBtn.dataset.itemTitle = String(item.title || 'Media item');
                deleteBtn.hidden = !(item.id && Number(item.id) > 0);
            }

            mmRenderEditPreview(item);
            modal.classList.add('active');
            modal.setAttribute('aria-hidden', 'false');

            var firstInput = document.getElementById('mm-edit-title');
            if (firstInput) {
                setTimeout(function() {
                    firstInput.focus();
                }, 120);
            }
        }

        function mmCloseEditModal() {
            var modal = document.getElementById('mmEditModal');
            if (!modal) {
                return;
            }

            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
        }

        function mmRequestConfirmation(options) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(options || {});
            }

            var message = options && options.message ? options.message : 'Are you sure you want to continue?';
            return Promise.resolve(window.confirm(message));
        }

        function mmDeleteFromModal() {
            var itemIdInput = document.getElementById('mm-edit-item-id');
            var itemId = parseInt(itemIdInput ? itemIdInput.value : '0', 10);
            if (!itemId) {
                return;
            }

            var itemTitleInput = document.getElementById('mm-edit-title');
            var itemTitle = itemTitleInput && itemTitleInput.value ? itemTitleInput.value : 'Media item';

            mmRequestConfirmation({
                title: 'Delete media item?',
                message: 'This permanently removes the media item and clears linked website references.',
                details: [itemTitle],
                confirmText: 'Delete item',
                cancelText: 'Cancel',
                tone: 'danger',
                icon: 'fa-trash-alt'
            }).then(function(confirmed) {
                if (!confirmed) {
                    return;
                }

                var form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';

                var fields = {
                    csrf_token: mmCsrfToken || '',
                    action: 'delete_item',
                    item_id: String(itemId)
                };

                Object.keys(fields).forEach(function(name) {
                    var input = document.createElement('input');
                    input.type = 'hidden';
                    input.name = name;
                    input.value = fields[name];
                    form.appendChild(input);
                });

                document.body.appendChild(form);
                form.submit();
            });
        }

        // Populate column datalist when source table is chosen
        (function() {
            var columnMap = <?= json_encode(mm_get_allowed_source_columns(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
            var tableSelect = document.getElementById('mm-link-table-create');
            var colInput = document.getElementById('mm-link-col-create');
            var colDl = document.getElementById('mm-column-hints-create');
            if (tableSelect && colInput && colDl) {
                tableSelect.addEventListener('change', function() {
                    var cols = columnMap[this.value] || [];
                    colDl.innerHTML = cols.map(function(c) {
                        return '<option value="' + c + '">';
                    }).join('');
                    colInput.value = cols.length === 1 ? cols[0] : '';
                });
            }
            // Rotate chevron on details toggle
            document.querySelectorAll('.mm-link-details').forEach(function(det) {
                det.addEventListener('toggle', function() {
                    var chev = det.querySelector('.mm-details-chevron');
                    if (chev) chev.style.transform = det.open ? 'rotate(180deg)' : '';
                });
            });

            var editModal = document.getElementById('mmEditModal');
            if (editModal) {
                editModal.addEventListener('click', function(event) {
                    if (event.target === editModal) {
                        mmCloseEditModal();
                    }
                });
            }

            var deleteBtn = document.getElementById('mm-modal-delete-btn');
            if (deleteBtn) {
                deleteBtn.addEventListener('click', mmDeleteFromModal);
            }

            document.addEventListener('keydown', function(event) {
                if (event.key === 'Escape' && editModal && editModal.classList.contains('active')) {
                    mmCloseEditModal();
                }
            });
        }());
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

