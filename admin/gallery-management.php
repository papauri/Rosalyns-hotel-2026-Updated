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

function syncHotelGalleryManagedMedia(array $item): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $id = $item['id'] ?? null;
    if (!$id) {
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
    // Size cap: 8 MB
    if (($fileInput['size'] ?? 0) > 8 * 1024 * 1024) {
        error_log('Gallery upload rejected: file > 8MB');
        return null;
    }
    // Extension whitelist
    $ext = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION));
    $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }
    // MIME validation
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileInput['tmp_name']) ?: '';
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }
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

// Handle POST actions
$isAjax  = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
$savedId = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $imagePath = uploadGalleryImage($_FILES['image'] ?? null);
            $imageUrl = $imagePath ?: ($_POST['image_url_external'] ?? '');

            if (empty($imageUrl)) {
                $error = 'Please provide an image (upload or URL).';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => $error]);
                    exit;
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
                }

                $stmt = $pdo->prepare("
                    INSERT INTO hotel_gallery (title, description, image_url, video_path, video_type, category, is_active, display_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['title'],
                    $_POST['description'] ?? '',
                    $imageUrl,
                    $videoPath,
                    $videoType,
                    $_POST['category'] ?? 'general',
                    isset($_POST['is_active']) ? 1 : 1,
                    $_POST['display_order'] ?? 0
                ]);

                $newId = (int)$pdo->lastInsertId();
                if ($newId > 0) {
                    syncHotelGalleryManagedMedia([
                        'id' => $newId,
                        'title' => $_POST['title'] ?? null,
                        'description' => $_POST['description'] ?? null,
                        'display_order' => $_POST['display_order'] ?? 0,
                        'image_url' => $imageUrl,
                        'video_path' => $videoPath,
                        'video_type' => $videoType,
                    ]);
                }

                $message = 'Gallery item added successfully!';
                $savedId = $newId;
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message, 'saved_id' => $savedId]);
                    exit;
                }
            }
        } elseif ($action === 'update') {
            $imagePath = uploadGalleryImage($_FILES['image'] ?? null);

            // Handle video
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            $videoPath = $videoUrl['path'] ?? null;
            $videoType = $videoUrl['type'] ?? null;

            if (!$videoPath) {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'gallery');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            $updateFields = ['title = ?', 'description = ?', 'category = ?', 'display_order = ?'];
            $updateValues = [
                $_POST['title'],
                $_POST['description'] ?? '',
                $_POST['category'] ?? 'general',
                $_POST['display_order'] ?? 0
            ];

            if ($imagePath) {
                $updateFields[] = 'image_url = ?';
                $updateValues[] = $imagePath;
            } elseif (!empty($_POST['image_url_external'])) {
                $updateFields[] = 'image_url = ?';
                $updateValues[] = $_POST['image_url_external'];
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

            $updateValues[] = $_POST['id'];

            $sql = "UPDATE hotel_gallery SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);

            $itemId = (int)($_POST['id'] ?? 0);
            if ($itemId > 0) {
                $mediaStmt = $pdo->prepare("SELECT id, title, description, display_order, image_url, video_path, video_type FROM hotel_gallery WHERE id = ? LIMIT 1");
                $mediaStmt->execute([$itemId]);
                $mediaItem = $mediaStmt->fetch(PDO::FETCH_ASSOC);
                if ($mediaItem) {
                    syncHotelGalleryManagedMedia($mediaItem);
                }
            }

            $message = 'Gallery item updated successfully!';
            $savedId = (int)($_POST['id'] ?? 0);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => true, 'message' => $message, 'saved_id' => $savedId]);
                exit;
            }
        } elseif ($action === 'delete') {
            $itemId = (int)($_POST['id'] ?? 0);
            if ($itemId > 0 && function_exists('upsertManagedMediaForSource')) {
                upsertManagedMediaForSource('hotel_gallery', $itemId, 'image_url', null, ['source_context' => '']);
                upsertManagedMediaForSource('hotel_gallery', $itemId, 'video_path', null, ['source_context' => '']);
            }

            // Get image path before deleting
            $stmt = $pdo->prepare("SELECT image_url, video_path FROM hotel_gallery WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare("DELETE FROM hotel_gallery WHERE id = ?");
            $stmt->execute([$_POST['id']]);

            // Delete local files
            if ($item) {
                if ($item['image_url'] && !preg_match('#^https?://#i', $item['image_url'])) {
                    $path = '../' . $item['image_url'];
                    if (file_exists($path)) @unlink($path);
                }
                if ($item['video_path'] && !preg_match('#^https?://#i', $item['video_path'])) {
                    $path = '../' . $item['video_path'];
                    if (file_exists($path)) @unlink($path);
                }
            }
            $message = 'Gallery item deleted successfully!';
        } elseif ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE hotel_gallery SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Gallery item status updated!';
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'message' => $error]);
            exit;
        }
    }
}

// Fetch all gallery items
try {
    $stmt = $pdo->query("SELECT * FROM hotel_gallery ORDER BY display_order ASC, created_at DESC");
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

        <div id="galleryPageFeedback" class="admin-modal-feedback" hidden></div>

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
                    <div class="gallery-card" data-category="<?php echo htmlspecialchars($item['category']); ?>">
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
            <div class="modal-header">
                <h3 id="modalTitle"><i class="fas fa-plus-circle"></i> Add Gallery Item</h3>
                <button class="modal-close" type="button" onclick="closeModal()">&times;</button>
            </div>
            <form method="POST" enctype="multipart/form-data" id="galleryForm">
                <input type="hidden" name="action" id="formAction" value="add">
                <input type="hidden" name="id" id="formId" value="">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

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

                <div class="form-actions" style="flex-direction:column; align-items:stretch; gap:0;">
                    <div id="galleryModalFeedback" class="admin-modal-feedback"></div>
                    <div style="display:flex; justify-content:flex-end; gap:10px;">
                        <button type="button" onclick="closeModal()" style="padding:10px 24px; border:1px solid #ddd; border-radius:6px; background:white; cursor:pointer;">Close</button>
                        <button type="submit" id="galleryFormSubmitBtn" style="padding:10px 24px; border:none; border-radius:6px; background:var(--gold, #8B7355); color:var(--deep-navy, #111111); font-weight:600; cursor:pointer;">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
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

