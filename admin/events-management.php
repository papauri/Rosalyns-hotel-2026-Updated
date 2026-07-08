<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var string $csrf_token */

// Include modal and alert helpers
require_once '../includes/modal.php';
require_once '../includes/alert.php';
require_once '../includes/video-display.php';
require_once 'video-upload-handler.php';
require_once '../config/email.php';

function syncEventManagedMedia(array $eventRow): void
{
    if (!function_exists('upsertManagedMediaForSource')) {
        return;
    }

    $eventId = $eventRow['id'] ?? null;
    if (!$eventId) {
        return;
    }

    upsertManagedMediaForSource('events', $eventId, 'image_path', $eventRow['image_path'] ?? null, [
        'title' => ($eventRow['title'] ?? 'Event') . ' (Image)',
        'description' => $eventRow['description'] ?? null,
        'caption' => $eventRow['description'] ?? null,
        'alt_text' => $eventRow['title'] ?? 'Event image',
        'placement_key' => 'events.image_path',
        'page_slug' => 'events',
        'section_key' => 'events_overview',
        'entity_type' => 'event',
        'entity_id' => (int)$eventId,
        'display_order' => (int)($eventRow['display_order'] ?? 0),
        'use_case' => 'event_card_image',
        'media_type' => 'image',
    ]);

    upsertManagedMediaForSource('events', $eventId, 'video_path', $eventRow['video_path'] ?? null, [
        'title' => ($eventRow['title'] ?? 'Event') . ' (Video)',
        'description' => $eventRow['description'] ?? null,
        'caption' => $eventRow['description'] ?? null,
        'alt_text' => $eventRow['title'] ?? 'Event video',
        'placement_key' => 'events.video_path',
        'page_slug' => 'events',
        'section_key' => 'events_overview',
        'entity_type' => 'event',
        'entity_id' => (int)$eventId,
        'display_order' => (int)($eventRow['display_order'] ?? 0),
        'use_case' => 'event_card_video',
        'media_type' => 'video',
        'mime_type' => $eventRow['video_type'] ?? null,
    ]);
}

// Note: $user and $current_page are already set in admin-init.php
$message = '';
$error = '';

// Helper: upload event image (hardened: size cap + extension + MIME + image content verification)
function uploadEventImage(array $fileInput)
{
    if (!$fileInput || !isset($fileInput['tmp_name']) || $fileInput['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    if (($fileInput['size'] ?? 0) > 8 * 1024 * 1024) {
        error_log('Event upload rejected: file > 8MB');
        return null;
    }

    $uploadDir = __DIR__ . '/../images/events/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $ext = strtolower(pathinfo($fileInput['name'], PATHINFO_EXTENSION)) ?: 'jpg';
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed, true)) {
        return null;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($fileInput['tmp_name']) ?: '';
    $allowedMime = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }
    if (!@getimagesize($fileInput['tmp_name'])) {
        return null;
    }

    $filename = 'event_' . time() . '_' . random_int(1000, 9999) . '.' . $ext;
    $destination = $uploadDir . $filename;

    if (move_uploaded_file($fileInput['tmp_name'], $destination)) {
        return 'images/events/' . $filename;
    }

    return null;
}

// Helper: get user-friendly upload error message
function getUploadErrorMessage(int $errorCode)
{
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'File is too large. Please resize or compress the image and try again.';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server error: No temporary folder. Please contact the administrator.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server error: Failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload was blocked by a server extension.';
        default:
            return 'Unknown upload error. Please try again.';
    }
}

// Handle event actions
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        if ($is_ajax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Security token invalid.']);
            exit;
        }
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    try {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            // Handle image upload
            $imagePath = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error = getUploadErrorMessage($_FILES['image']['error']);
                } else {
                    $imagePath = uploadEventImage($_FILES['image']);
                    if (!$imagePath) {
                        $error = 'Invalid image type. Only JPG, PNG, and WebP files are allowed.';
                    }
                }
            }

            // Check for video URL first, then file upload
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'events');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO events (title, description, event_date, start_time, end_time, location, ticket_price, capacity, is_featured, show_in_upcoming, is_active, display_order, image_path, video_path, video_type)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_POST['title'],
                $_POST['description'],
                $_POST['event_date'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['location'],
                $_POST['ticket_price'] ?? 0,
                $_POST['capacity'],
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['show_in_upcoming']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['display_order'] ?? 0,
                $imagePath,
                $videoPath,
                $videoType
            ]);

            $newEventId = (int)$pdo->lastInsertId();
            if ($newEventId > 0) {
                syncEventManagedMedia([
                    'id' => $newEventId,
                    'title' => $_POST['title'] ?? null,
                    'description' => $_POST['description'] ?? null,
                    'display_order' => $_POST['display_order'] ?? 0,
                    'image_path' => $imagePath,
                    'video_path' => $videoPath,
                    'video_type' => $videoType,
                ]);
            }

            if (!$error) {
                $message = 'Event added successfully!';
                if ($is_ajax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message, 'saved_id' => $newEventId]);
                    exit;
                }
            } elseif ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
        } elseif ($action === 'update') {
            // Handle image upload
            $imagePath = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error = getUploadErrorMessage($_FILES['image']['error']);
                } else {
                    $imagePath = uploadEventImage($_FILES['image']);
                    if (!$imagePath) {
                        $error = 'Invalid image type. Only JPG, PNG, and WebP files are allowed.';
                    }
                }
            }

            // Check for video URL first, then file upload
            $videoUrl = processVideoUrl($_POST['video_url'] ?? '');
            if ($videoUrl) {
                $videoPath = $videoUrl['path'];
                $videoType = $videoUrl['type'];
            } else {
                $videoUpload = uploadVideo($_FILES['video'] ?? null, 'events');
                $videoPath = $videoUpload['path'] ?? null;
                $videoType = $videoUpload['type'] ?? null;
            }

            // Build the update query dynamically based on what's being updated
            $updateFields = [
                'title = ?',
                'description = ?',
                'event_date = ?',
                'start_time = ?',
                'end_time = ?',
                'location = ?',
                'ticket_price = ?',
                'capacity = ?',
                'is_featured = ?',
                'show_in_upcoming = ?',
                'is_active = ?',
                'display_order = ?'
            ];
            $updateValues = [
                $_POST['title'],
                $_POST['description'] ?? '',
                $_POST['event_date'],
                $_POST['start_time'],
                $_POST['end_time'],
                $_POST['location'],
                $_POST['ticket_price'] ?? 0,
                $_POST['capacity'],
                isset($_POST['is_featured']) ? 1 : 0,
                isset($_POST['show_in_upcoming']) ? 1 : 0,
                isset($_POST['is_active']) ? 1 : 0,
                $_POST['display_order'] ?? 0
            ];

            if ($imagePath) {
                $updateFields[] = 'image_path = ?';
                $updateValues[] = $imagePath;
            }

            if ($videoPath) {
                $updateFields[] = 'video_path = ?';
                $updateValues[] = $videoPath;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = $videoType;
            } elseif (!empty($_POST['remove_video'])) {
                // User explicitly wants to remove the video
                $updateFields[] = 'video_path = ?';
                $updateValues[] = null;
                $updateFields[] = 'video_type = ?';
                $updateValues[] = null;
            }

            $updateValues[] = $_POST['id'];

            $sql = "UPDATE events SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updateValues);

            $eventId = (int)($_POST['id'] ?? 0);
            if ($eventId > 0) {
                $mediaStmt = $pdo->prepare("SELECT id, title, description, display_order, image_path, video_path, video_type FROM events WHERE id = ? LIMIT 1");
                $mediaStmt->execute([$eventId]);
                $eventForMedia = $mediaStmt->fetch(PDO::FETCH_ASSOC);
                if ($eventForMedia) {
                    syncEventManagedMedia($eventForMedia);
                }
            }

            if (!$error) {
                $message = 'Event updated successfully!';
                if ($is_ajax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => true, 'message' => $message, 'saved_id' => $eventId]);
                    exit;
                }
            } elseif ($is_ajax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
        } elseif ($action === 'delete') {
            $eventId = (int)($_POST['id'] ?? 0);
            if ($eventId > 0 && function_exists('upsertManagedMediaForSource')) {
                upsertManagedMediaForSource('events', $eventId, 'image_path', null, ['source_context' => '']);
                upsertManagedMediaForSource('events', $eventId, 'video_path', null, ['source_context' => '']);
            }

            $stmt = $pdo->prepare("DELETE FROM events WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Event deleted successfully!';
        } elseif ($action === 'toggle_active') {
            $stmt = $pdo->prepare("UPDATE events SET is_active = NOT is_active WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Event status updated!';
        } elseif ($action === 'toggle_featured') {
            $stmt = $pdo->prepare("UPDATE events SET is_featured = NOT is_featured WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Featured status updated!';
        } elseif ($action === 'toggle_upcoming') {
            $stmt = $pdo->prepare("UPDATE events SET show_in_upcoming = NOT show_in_upcoming WHERE id = ?");
            $stmt->execute([$_POST['id']]);
            $message = 'Upcoming display status updated!';
        } elseif ($action === 'save_upcoming_settings') {
            // Save upcoming events section settings
            $ue_enabled = isset($_POST['ue_enabled']) ? '1' : '0';
            $ue_max = max(1, min(8, intval($_POST['ue_max_display'] ?? 4)));
            $ue_pages = $_POST['ue_pages'] ?? [];
            $ue_pages_json = json_encode(array_values($ue_pages));

            $stmtSetting = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value, setting_group) VALUES (?, ?, 'upcoming_events') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            $stmtSetting->execute(['upcoming_events_enabled', $ue_enabled]);
            $stmtSetting->execute(['upcoming_events_max_display', (string)$ue_max]);
            $stmtSetting->execute(['upcoming_events_pages', $ue_pages_json]);

            // Clear cached settings (in-memory + file cache)
            global $_SITE_SETTINGS;
            unset($_SITE_SETTINGS['upcoming_events_enabled'], $_SITE_SETTINGS['upcoming_events_max_display'], $_SITE_SETTINGS['upcoming_events_pages']);
            if (function_exists('clearCacheByPattern')) {
                clearCacheByPattern('setting_upcoming_events*');
            }

            $message = 'Upcoming events section settings saved!';
        } elseif ($action === 'send_event_quotation') {
            $eventId = (int)($_POST['event_id'] ?? 0);
            $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
            $recipientEmail = trim((string)($_POST['recipient_email'] ?? ''));
            $recipientPhone = trim((string)($_POST['recipient_phone'] ?? ''));
            $attendeeCount = max(1, (int)($_POST['attendee_count'] ?? 1));
            $validDays = max(1, min(30, (int)($_POST['quotation_valid_days'] ?? 7)));
            $quotationNotes = trim((string)($_POST['quotation_notes'] ?? ''));
            $sendWhatsapp = isset($_POST['send_whatsapp']);

            if ($eventId <= 0) {
                throw new Exception('Invalid event selected for quotation.');
            }
            if ($recipientName === '') {
                throw new Exception('Recipient name is required.');
            }
            if ($recipientEmail === '' || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('A valid recipient email is required.');
            }

            $eventStmt = $pdo->prepare('SELECT * FROM events WHERE id = ? LIMIT 1');
            $eventStmt->execute([$eventId]);
            $eventRow = $eventStmt->fetch(PDO::FETCH_ASSOC);
            if (!$eventRow) {
                throw new Exception('Event not found.');
            }

            $quoteResult = sendEventQuotationEmail($eventRow, [
                'recipient_name' => $recipientName,
                'recipient_email' => $recipientEmail,
                'recipient_phone' => $recipientPhone,
                'attendee_count' => $attendeeCount,
                'valid_days' => $validDays,
                'quotation_notes' => $quotationNotes,
                'attach_pdf' => true,
                'send_whatsapp' => $sendWhatsapp,
            ]);

            if (!empty($quoteResult['success'])) {
                $message = 'Event quotation sent to ' . htmlspecialchars($recipientEmail, ENT_QUOTES, 'UTF-8') . '.';
                if (!empty($quoteResult['whatsapp']['success'])) {
                    $message .= ' WhatsApp delivered.';
                } elseif (!empty($quoteResult['whatsapp']['message']) && !in_array($quoteResult['whatsapp']['message'], ['No recipient phone', 'WhatsApp disabled'], true)) {
                    $message .= ' WhatsApp issue: ' . $quoteResult['whatsapp']['message'];
                }
            } else {
                throw new Exception('Failed to send quotation: ' . ($quoteResult['message'] ?? 'Unknown error'));
            }
        } elseif ($action === 'update_image') {
            if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($_FILES['image']['error'] !== UPLOAD_ERR_OK) {
                    $error = getUploadErrorMessage($_FILES['image']['error']);
                } else {
                    $imagePath = uploadEventImage($_FILES['image']);
                    if ($imagePath) {
                        $stmt = $pdo->prepare("UPDATE events SET image_path = ? WHERE id = ?");
                        $stmt->execute([$imagePath, $_POST['id']]);
                        $message = 'Event image updated!';
                    } else {
                        $error = 'Invalid image type. Only JPG, PNG, and WebP files are allowed.';
                    }
                }
            } else {
                $error = 'Please select an image to upload.';
            }
        }
    } catch (PDOException $e) {
        $error = 'Error: ' . $e->getMessage();
    }
}

// Fetch all events
try {
    $stmt = $pdo->query("
        SELECT * FROM events
        ORDER BY event_date ASC, display_order ASC
    ");
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark expired events
    $today = date('Y-m-d');
    foreach ($events as &$event) {
        $event['is_expired'] = ($event['event_date'] < $today);
    }
    unset($event);
} catch (PDOException $e) {
    $error = 'Error fetching events: ' . $e->getMessage();
    $events = [];
}
$fb_events_posting_on = getSetting('facebook_posting_enabled', '0') === '1'
    && getSetting('facebook_events_enabled', '1') === '1'
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
    <title>Events Management - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/events-management.css?v=<?php echo @filemtime(__DIR__ . '/css/events-management.css'); ?>">
    <link rel="stylesheet" href="css/facebook-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/facebook-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <div>
                <h2 class="page-title"><i class="fas fa-calendar-alt"></i> Events Management</h2>
                <p style="color:#666; margin-top:4px;"><?php echo count($events); ?> event<?php echo count($events) !== 1 ? 's' : ''; ?> total</p>
            </div>
            <button class="btn-add" onclick="openAddModal()">
                <i class="fas fa-plus"></i> Add New Event
            </button>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>

        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Upcoming Events Section Settings -->
        <?php
        $ue_enabled = getSetting('upcoming_events_enabled', '1');
        $ue_max_display = getSetting('upcoming_events_max_display', '4');
        $ue_pages_json = getSetting('upcoming_events_pages', '["index"]');
        $ue_pages = json_decode($ue_pages_json, true) ?: ['index'];

        // Available pages where the section can be shown
        $available_pages = [
            'index' => 'Homepage',
            'rooms-showcase' => 'Rooms Showcase',
            'restaurant' => 'Restaurant',
            'conference' => 'Conference',
            'gym' => 'Gym'
        ];
        ?>
        <div style="background: linear-gradient(135deg, #e8f5e9, #f1f8e9); border: 1px solid #c8e6c9; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px;">
            <div style="display: flex; align-items: center; justify-content: space-between; cursor: pointer;" onclick="document.getElementById('ueSettingsBody').style.display = document.getElementById('ueSettingsBody').style.display === 'none' ? 'block' : 'none'; this.querySelector('.ue-chevron').classList.toggle('fa-chevron-down'); this.querySelector('.ue-chevron').classList.toggle('fa-chevron-up');">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <i class="fas fa-bullhorn" style="color: #2e7d32; font-size: 18px;"></i>
                    <span style="font-weight: 600; color: #1b5e20; font-size: 15px;">Upcoming Events Section Settings</span>
                    <span style="font-size: 12px; padding: 2px 10px; border-radius: 10px; background: <?php echo $ue_enabled === '1' ? '#4caf50' : '#999'; ?>; color: white;"><?php echo $ue_enabled === '1' ? 'Enabled' : 'Disabled'; ?></span>
                </div>
                <i class="fas fa-chevron-down ue-chevron" style="color: #666; font-size: 13px;"></i>
            </div>
            <div id="ueSettingsBody" style="display: none; margin-top: 16px;">
                <form method="POST" style="display: flex; flex-direction: column; gap: 14px;">
                    <input type="hidden" name="action" value="save_upcoming_settings">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" name="ue_enabled" id="ueEnabled" <?php echo $ue_enabled === '1' ? 'checked' : ''; ?> style="width: auto;">
                        <label for="ueEnabled" style="font-weight: 500; font-size: 14px; color: #333;">Enable Upcoming Events section on public pages</label>
                    </div>

                    <div style="display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end;">
                        <div style="flex: 1; min-width: 180px;">
                            <label style="font-size: 13px; font-weight: 500; color: #555; display: block; margin-bottom: 4px;">Max Events to Display</label>
                            <select name="ue_max_display" style="width: 100%; padding: 8px 12px; border: 1px solid #ccc; border-radius: 8px; font-size: 14px; background: white;">
                                <?php for ($i = 1; $i <= 8; $i++): ?>
                                    <option value="<?php echo $i; ?>" <?php echo $ue_max_display == $i ? 'selected' : ''; ?>><?php echo $i; ?> event<?php echo $i > 1 ? 's' : ''; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label style="font-size: 13px; font-weight: 500; color: #555; display: block; margin-bottom: 6px;">Show on Pages</label>
                        <div style="display: flex; flex-wrap: wrap; gap: 8px 16px;">
                            <?php foreach ($available_pages as $page_key => $page_label): ?>
                                <label style="display: flex; align-items: center; gap: 6px; font-size: 13px; color: #444; cursor: pointer;">
                                    <input type="checkbox" name="ue_pages[]" value="<?php echo $page_key; ?>" <?php echo in_array($page_key, $ue_pages) ? 'checked' : ''; ?> style="width: auto;">
                                    <?php echo $page_label; ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div>
                        <button type="submit" style="background: #2e7d32; color: white; border: none; padding: 10px 24px; border-radius: 8px; font-size: 14px; font-weight: 500; cursor: pointer; transition: background 0.2s;" onmouseover="this.style.background='#1b5e20'" onmouseout="this.style.background='#2e7d32'">
                            <i class="fas fa-save"></i> Save Settings
                        </button>
                    </div>
                </form>
                <p style="margin-top: 10px; font-size: 12px; color: #666;">
                    <i class="fas fa-info-circle"></i> Use the <strong>"Upcoming"</strong> button on each event card below to choose which events appear in this section. Only active, future-dated events marked for the upcoming section will display.
                </p>
            </div>
        </div>

        <div class="events-section">
            <?php if (!empty($events)): ?>
                <div class="events-grid" id="eventsGrid">
                    <?php foreach ($events as $event): ?>
                        <div class="event-card <?php echo $event['is_expired'] ? 'expired' : ''; ?>">
                            <?php
                            // Prioritize video over image — but a URL ending in an image
                            // extension is NOT a video (legacy data sometimes stored an image
                            // in video_path). Treat those as images so they crop instead of
                            // being embedded in an iframe at full size.
                            $rawVideo = $event['video_path'] ?? '';
                            $videoIsImage = $rawVideo !== '' && preg_match('/\.(jpe?g|png|webp|gif|avif)([?#].*)?$/i', $rawVideo);
                            $hasVideo = $rawVideo !== '' && !$videoIsImage;
                            $imgSrc = $event['image_path'] ?? '';
                            if ($imgSrc === '' && $videoIsImage) {
                                $imgSrc = $rawVideo;
                            }
                            if ($imgSrc && !preg_match('#^https?://#i', $imgSrc)) {
                                $imgSrc = '../' . $imgSrc;
                            }
                            ?>

                            <div class="event-card-media">
                            <?php if ($hasVideo): ?>
                                <?php echo renderVideoEmbed($event['video_path'], $event['video_type'], ['autoplay' => false, 'muted' => false, 'style' => 'width: 100%; height: 100%; object-fit: cover;']); ?>
                            <?php elseif ($imgSrc): ?>
                                <img src="<?php echo htmlspecialchars($imgSrc); ?>"
                                    alt="<?php echo htmlspecialchars($event['title']); ?>"
                                    class="event-card-image"
                                    onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="no-image-placeholder" style="display:none;"><i class="fas fa-calendar-alt"></i></div>
                            <?php else: ?>
                                <div class="no-image-placeholder"><i class="fas fa-calendar-alt"></i></div>
                            <?php endif; ?>
                            </div>

                            <div class="event-card-body">
                                <div class="event-card-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                <?php if (!empty($event['description'])): ?>
                                    <div class="event-card-desc"><?php echo htmlspecialchars(substr($event['description'], 0, 100)); ?></div>
                                <?php endif; ?>

                                <div class="event-card-info">
                                    <div class="event-card-info-item">
                                        <i class="fas fa-calendar"></i>
                                        <span><?php echo date('M d, Y', strtotime($event['event_date'])); ?></span>
                                    </div>
                                    <?php if ($event['start_time']): ?>
                                        <div class="event-card-info-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo date('H:i', strtotime($event['start_time'])); ?> - <?php echo date('H:i', strtotime($event['end_time'])); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($event['location']): ?>
                                        <div class="event-card-info-item event-card-info-full">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($event['location']); ?></span>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($event['capacity']): ?>
                                        <div class="event-card-info-item">
                                            <i class="fas fa-users"></i>
                                            <span><?php echo $event['capacity']; ?> seats</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="event-card-meta">
                                    <?php if ($event['is_expired']): ?>
                                        <span class="badge badge-expired"><i class="fas fa-calendar-times"></i> Expired</span>
                                    <?php endif; ?>
                                    <?php if ($event['is_active']): ?>
                                        <span class="badge badge-active"><i class="fas fa-check-circle"></i> Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-inactive"><i class="fas fa-times-circle"></i> Inactive</span>
                                    <?php endif; ?>
                                    <?php if ($event['is_featured']): ?>
                                        <span class="badge badge-featured"><i class="fas fa-star"></i> Featured</span>
                                    <?php endif; ?>
                                    <?php if (!empty($event['show_in_upcoming'])): ?>
                                        <span class="badge badge-upcoming"><i class="fas fa-bullhorn"></i> Upcoming Section</span>
                                    <?php endif; ?>
                                    <?php if ($event['ticket_price'] == 0): ?>
                                        <span class="badge badge-free"><i class="fas fa-ticket-alt"></i> Free</span>
                                    <?php else: ?>
                                        <span class="badge badge-price"><i class="fas fa-tag"></i> <?php echo htmlspecialchars(getSetting('currency_symbol')); ?><?php echo number_format($event['ticket_price'], 2); ?></span>
                                    <?php endif; ?>
                                    <?php if (!empty($event['video_path'])): ?>
                                        <span class="badge badge-video"><i class="fas fa-video"></i> Video</span>
                                    <?php endif; ?>
                                    <span style="font-size:11px; color:#999;">Order: <?php echo $event['display_order']; ?></span>
                                </div>

                                <div class="event-card-actions">
                                    <button class="btn-action btn-edit" type="button" onclick='openEditModal(<?php echo htmlspecialchars(json_encode($event), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-edit"></i> Edit
                                    </button>
                                    <button class="btn-action btn-quote" type="button" onclick='openEventQuoteModal(<?php echo htmlspecialchars(json_encode($event), ENT_QUOTES, "UTF-8"); ?>)'>
                                        <i class="fas fa-file-signature"></i> Quote
                                    </button>
                                    <button class="btn-action btn-toggle" type="button" onclick="toggleActive(<?php echo $event['id']; ?>)">
                                        <i class="fas fa-power-off"></i> Toggle
                                    </button>
                                    <button class="btn-action btn-featured" type="button" onclick="toggleFeatured(<?php echo $event['id']; ?>)">
                                        <i class="fas fa-star"></i> Featured
                                    </button>
                                    <button class="btn-action btn-upcoming" type="button" onclick="toggleUpcoming(<?php echo $event['id']; ?>)">
                                        <i class="fas fa-bullhorn"></i> Upcoming
                                    </button>
                                    <?php if ($fb_events_posting_on): ?>
                                        <button class="btn-action btn-facebook" type="button" title="Post to Facebook"
                                            onclick='openFbEventShareModal(<?php echo (int)$event["id"]; ?>, <?php echo htmlspecialchars(json_encode($event["title"]), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($event["image_path"] ?? ""), ENT_QUOTES, "UTF-8"); ?>, <?php echo htmlspecialchars(json_encode($event["event_date"] ?? ""), ENT_QUOTES, "UTF-8"); ?>)'>
                                            <i class="fab fa-facebook-f"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn-action btn-delete" type="button" onclick="if(confirm('Delete this event?')) deleteEvent(<?php echo $event['id']; ?>)">
                                        <i class="fas fa-trash-alt"></i> Delete
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 60px 20px; color: #999;">
                    <i class="fas fa-calendar-times" style="font-size: 64px; margin-bottom: 16px; color: #ddd;"></i>
                    <p>No events found. Click "Add New Event" to create your first event.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Add Event Modal -->
    <?php
    renderModal('eventModal', 'Add New Event', '
        <form method="POST" action="events-management.php" id="eventForm" enctype="multipart/form-data">
            <input type="hidden" name="action" id="formAction" value="add">
            <input type="hidden" name="id" id="eventId">
            <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') . '">

            <div class="form-group">
                <label>Event Title *</label>
                <input type="text" name="title" id="eventTitle" required>
            </div>

            <div class="form-group">
                <label>Description *</label>
                <textarea name="description" id="eventDescription" required></textarea>
            </div>

            <div class="form-group">
                <label>Event Date *</label>
                <input type="date" name="event_date" id="eventDate" required>
            </div>

            <div class="form-group">
                <label>Start Time</label>
                <input type="time" name="start_time" id="eventStartTime">
            </div>

            <div class="form-group">
                <label>End Time</label>
                <input type="time" name="end_time" id="eventEndTime">
            </div>

            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" id="eventLocation" placeholder="e.g., Grand Conference Hall">
            </div>

            <div class="form-group">
                <label>Ticket Price (' . htmlspecialchars(getSetting('currency_symbol')) . ')</label>
                <input type="number" name="ticket_price" id="eventPrice" step="0.01" value="0">
                <small style="color: #666;">Enter 0 for free events</small>
            </div>

            <div class="form-group">
                <label>Capacity</label>
                <input type="number" name="capacity" id="eventCapacity">
            </div>

            <div class="form-group">
                <label>Display Order</label>
                <input type="number" name="display_order" id="eventOrder" value="0">
            </div>

            <div class="form-group">
                <label>Event Image</label>
                <div class="image-upload-wrapper">
                    <div class="image-upload-area" onclick="document.getElementById(\'eventImage\').click()">
                        <i class="fas fa-cloud-upload-alt"></i>
                        <div style="font-weight: 600; margin-bottom: 4px;">Click to Upload Event Image</div>
                        <div style="font-size: 12px; color: #999;">Recommended: 1200x800px (JPG, PNG, WebP)</div>
                    </div>
                    <input type="file" name="image" id="eventImage" accept="image/jpeg,image/png,image/jpg,image/webp" style="display: none;" onchange="previewModalImage(this)">
                    <div id="imagePreviewContainer" style="margin-top: 16px; display: none;">
                        <img id="imagePreview" class="current-image-preview" alt="Preview">
                        <div style="font-size: 12px; color: #666; margin-top: 8px;">
                            <i class="fas fa-check-circle" style="color: #28a745;"></i>
                            <span id="imageFileName"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label>Event Video (Optional)</label>

                <!-- Video URL Input -->
                <div style="margin-bottom: 12px;">
                    <label style="font-size: 13px; font-weight: 500; display: block; margin-bottom: 4px;">
                        <i class="fas fa-link"></i> Video URL (YouTube, Vimeo, or direct link)
                    </label>
                    <input type="url" name="video_url" id="eventVideoUrl"
                           placeholder="https://www.youtube.com/watch?v=... or https://vimeo.com/..."
                           style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;">
                    <small style="color: #888;">Paste a YouTube, Vimeo, Dailymotion, or direct video URL</small>
                </div>

                <div style="text-align: center; color: #999; font-size: 12px; margin-bottom: 12px;">— OR upload a file —</div>

                <input type="hidden" name="remove_video" id="removeVideoFlag" value="0">
                <div id="removeVideoSection" style="display: none; margin-bottom: 12px;">
                    <div style="background: #fff3cd; padding: 10px 14px; border-radius: 8px; border: 1px solid #ffc107; display: flex; align-items: center; justify-content: space-between;">
                        <span style="font-size: 13px; color: #856404;"><i class="fas fa-video"></i> <span id="currentVideoLabel">Current video attached</span></span>
                        <button type="button" onclick="removeEventVideo()" style="background: #dc3545; color: white; border: none; padding: 6px 14px; border-radius: 5px; cursor: pointer; font-size: 12px; font-weight: 600;">
                            <i class="fas fa-trash-alt"></i> Remove Video
                        </button>
                    </div>
                </div>

                <div class="video-upload-wrapper">
                    <div class="image-upload-area" onclick="document.getElementById(\'eventVideo\').click()" style="border-color: #9b59b6;">
                        <i class="fas fa-video" style="color: #9b59b6;"></i>
                        <div style="font-weight: 600; margin-bottom: 4px;">Click to Upload Event Video</div>
                        <div style="font-size: 12px; color: #999;">MP4, WebM, OGG (Max 100MB)</div>
                    </div>
                    <input type="file" name="video" id="eventVideo" accept="video/*" style="display: none;" onchange="previewModalVideo(this)">
                    <div id="videoPreviewContainer" style="margin-top: 16px; display: none;">
                        <div style="background: #f3e5f5; padding: 12px; border-radius: 8px; border: 1px solid #9b59b6;">
                            <i class="fas fa-check-circle" style="color: #9b59b6;"></i>
                            <span id="videoFileName" style="font-size: 14px; color: #4a148c;"></span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="is_featured" id="eventFeatured">
                <label for="eventFeatured">Feature this event</label>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="show_in_upcoming" id="eventUpcoming">
                <label for="eventUpcoming">Show in Upcoming Events section (homepage)</label>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="is_active" id="eventActive" checked>
                <label for="eventActive">Active (visible on website)</label>
            </div>

            <div id="eventModalFeedback" class="admin-modal-feedback"></div>
            <div style="display: flex; gap: 10px; justify-content: flex-end; padding-top: 20px; border-top: 1px solid #eee; margin-top: 20px;">
                <button type="button" class="btn-action btn-cancel" onclick="closeEventModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="submit" id="eventSaveBtn" class="btn-action btn-save">
                    <i class="fas fa-save"></i> Save
                </button>
            </div>
        </form>
    ', [
        'size' => 'lg',
        'show_close' => false
    ]);

    renderModal('eventQuoteModal', 'Send Event Quotation', '
        <form method="POST" action="events-management.php" id="eventQuoteForm">
            <input type="hidden" name="action" value="send_event_quotation">
            <input type="hidden" name="event_id" id="quoteEventId">
            <input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') . '">

            <div class="form-group">
                <label>Event</label>
                <input type="text" id="quoteEventTitle" class="form-control" readonly>
            </div>

            <div class="form-group">
                <label>Recipient Name *</label>
                <input type="text" name="recipient_name" id="quoteRecipientName" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Recipient Email *</label>
                <input type="email" name="recipient_email" id="quoteRecipientEmail" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Recipient Phone (for WhatsApp)</label>
                <input type="text" name="recipient_phone" id="quoteRecipientPhone" class="form-control" placeholder="+265...">
            </div>

            <div class="form-row-2">
                <div class="form-group">
                    <label>Attendee Count *</label>
                    <input type="number" name="attendee_count" id="quoteAttendeeCount" class="form-control" value="1" min="1" required>
                </div>
                <div class="form-group">
                    <label>Valid For (days)</label>
                    <input type="number" name="quotation_valid_days" id="quoteValidDays" class="form-control" value="7" min="1" max="30">
                </div>
            </div>

            <div class="form-group">
                <label>Quotation Notes</label>
                <textarea name="quotation_notes" id="quoteNotes" class="form-control" rows="4" placeholder="Optional notes to include in the quotation"></textarea>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" name="send_whatsapp" id="quoteSendWhatsapp" value="1" checked>
                <label for="quoteSendWhatsapp">Also send via WhatsApp (when phone is provided and WhatsApp is enabled)</label>
            </div>

            <div style="display:flex; gap:10px; justify-content:flex-end; padding-top:16px; border-top:1px solid #eee; margin-top:12px;">
                <button type="button" class="btn-action btn-cancel" onclick="closeEventQuoteModal()">
                    <i class="fas fa-times"></i> Close
                </button>
                <button type="submit" class="btn-action btn-save">
                    <i class="fas fa-paper-plane"></i> Send Quotation
                </button>
            </div>
        </form>
    ', [
        'size' => 'md',
        'show_close' => false
    ]);
    ?>

    <script src="js/admin-components.js"></script>
    <script>
        // Direct modal control - works regardless of Modal object
        function openEventModal() {
            const modal = document.getElementById('eventModal');
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('modal-open');
            }
        }

        function closeEventModal() {
            const modal = document.getElementById('eventModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.classList.remove('modal-open');
            }
            const fb = document.getElementById('eventModalFeedback');
            if (fb) {
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';
            }
        }

        function openEventQuoteModal(event) {
            const form = document.getElementById('eventQuoteForm');
            const modal = document.getElementById('eventQuoteModal');
            if (!form || !modal) {
                return;
            }

            form.reset();

            const eventTitle = event && event.title ? event.title : 'Event';
            const eventDate = event && event.event_date ? event.event_date : '';

            document.getElementById('quoteEventId').value = event && event.id ? event.id : '';
            document.getElementById('quoteEventTitle').value = eventTitle;
            document.getElementById('quoteAttendeeCount').value = 1;
            document.getElementById('quoteValidDays').value = 7;
            document.getElementById('quoteNotes').value = eventDate ?
                'Quotation for ' + eventTitle + ' scheduled on ' + eventDate + '.' :
                'Quotation for ' + eventTitle + '.';
            document.getElementById('quoteSendWhatsapp').checked = true;

            modal.classList.add('active');
            document.body.classList.add('modal-open');
        }

        function closeEventQuoteModal() {
            const modal = document.getElementById('eventQuoteModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.classList.remove('modal-open');
            }
        }

        function openAddModal() {
            document.getElementById('formAction').value = 'add';
            document.getElementById('eventForm').reset();
            document.getElementById('eventActive').checked = true;
            document.getElementById('imagePreviewContainer').style.display = 'none';
            document.getElementById('videoPreviewContainer').style.display = 'none';
            document.getElementById('removeVideoSection').style.display = 'none';
            document.getElementById('removeVideoFlag').value = '0';
            document.getElementById('eventVideoUrl').value = '';

            // Update the modal title (renderModal uses .modal__title BEM class)
            const modalTitle = document.querySelector('#eventModal .modal__title');
            if (modalTitle) {
                modalTitle.textContent = 'Add New Event';
            }
            openEventModal();
        }

        function removeEventVideo() {
            // Set the hidden flag to tell backend to clear video
            document.getElementById('removeVideoFlag').value = '1';
            // Clear the video URL input
            document.getElementById('eventVideoUrl').value = '';
            // Hide video preview
            document.getElementById('videoPreviewContainer').style.display = 'none';
            // Hide the remove section and show confirmation
            document.getElementById('removeVideoSection').innerHTML =
                '<div style="background: #f8d7da; padding: 10px 14px; border-radius: 8px; border: 1px solid #f5c6cb;">' +
                '<span style="font-size: 13px; color: #721c24;"><i class="fas fa-check-circle"></i> Video will be removed when you save.</span>' +
                '</div>';
        }

        function openEditModal(event) {
            document.getElementById('formAction').value = 'update';
            document.getElementById('eventId').value = event.id;
            document.getElementById('eventTitle').value = event.title || '';
            document.getElementById('eventDescription').value = event.description || '';
            document.getElementById('eventDate').value = event.event_date || '';
            document.getElementById('eventStartTime').value = event.start_time || '';
            document.getElementById('eventEndTime').value = event.end_time || '';
            document.getElementById('eventLocation').value = event.location || '';
            document.getElementById('eventPrice').value = event.ticket_price || 0;
            document.getElementById('eventCapacity').value = event.capacity || '';
            document.getElementById('eventOrder').value = event.display_order || 0;
            document.getElementById('eventFeatured').checked = event.is_featured == 1;
            document.getElementById('eventUpcoming').checked = event.show_in_upcoming == 1;
            document.getElementById('eventActive').checked = event.is_active == 1;

            // Show existing image if available
            if (event.image_path) {
                const imgSrc = event.image_path.startsWith('http') ? event.image_path : '../' + event.image_path;
                document.getElementById('imagePreview').src = imgSrc;
                document.getElementById('imageFileName').textContent = 'Current: ' + (event.image_path.split('/').pop() || 'image');
                document.getElementById('imagePreviewContainer').style.display = 'block';
            } else {
                document.getElementById('imagePreviewContainer').style.display = 'none';
            }

            // Show existing video URL if it's an external URL
            if (event.video_path) {
                const isUrl = event.video_path.startsWith('http://') || event.video_path.startsWith('https://');
                if (isUrl) {
                    document.getElementById('eventVideoUrl').value = event.video_path;
                } else {
                    // It's an uploaded file
                    document.getElementById('videoFileName').textContent = 'Current: ' + (event.video_path.split('/').pop() || 'video file');
                    document.getElementById('videoPreviewContainer').style.display = 'block';
                }
                // Show remove video section
                const videoName = event.video_path.split('/').pop() || 'video';
                document.getElementById('currentVideoLabel').textContent = 'Current: ' + videoName;
                document.getElementById('removeVideoSection').style.display = 'block';
                document.getElementById('removeVideoFlag').value = '0';
            } else {
                document.getElementById('eventVideoUrl').value = '';
                document.getElementById('videoPreviewContainer').style.display = 'none';
                document.getElementById('removeVideoSection').style.display = 'none';
                document.getElementById('removeVideoFlag').value = '0';
            }

            // Update the modal title (renderModal uses .modal__title BEM class)
            const modalTitle = document.querySelector('#eventModal .modal__title');
            if (modalTitle) {
                modalTitle.textContent = 'Edit Event';
            }

            openEventModal();
        }

        // ── AJAX save — keep modal open ──────────────────────────────────────
        document.getElementById('eventForm').addEventListener('submit', function(e) {
            e.preventDefault();
            const saveBtn = document.getElementById('eventSaveBtn');
            const fb = document.getElementById('eventModalFeedback');
            const origHtml = saveBtn.innerHTML;
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
            fb.className = 'admin-modal-feedback';
            fb.innerHTML = '';
            fetch('events-management.php', {
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
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback ' + (res.success ? 'admin-modal-feedback--success' : 'admin-modal-feedback--error') + ' visible';
                    fb.innerHTML = '<i class="fas fa-' + (res.success ? 'check-circle' : 'exclamation-circle') + '"></i> ' + res.message;
                    if (res.success) {
                        if (res.saved_id && document.getElementById('formAction').value === 'add') {
                            document.getElementById('formAction').value = 'update';
                            document.getElementById('eventId').value = res.saved_id;
                            const t = document.querySelector('#eventModal .modal__title');
                            if (t) t.textContent = 'Edit Event';
                        }
                        refreshEventsGrid();
                    }
                })
                .catch(function() {
                    saveBtn.disabled = false;
                    saveBtn.innerHTML = origHtml;
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                });
        });

        function refreshEventsGrid() {
            fetch(window.location.href)
                .then(function(r) {
                    return r.text();
                })
                .then(function(html) {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const next = doc.getElementById('eventsGrid');
                    const cur = document.getElementById('eventsGrid');
                    if (next && cur) cur.outerHTML = next.outerHTML;
                }).catch(function() {});
        }

        function deleteEvent(id) {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        if (typeof Alert !== 'undefined') {
                            Alert.show('Error deleting event', 'error');
                        } else {
                            alert('Error deleting event');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error deleting event', 'error');
                    } else {
                        alert('Error deleting event');
                    }
                });
        }

        function toggleActive(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_active');
            formData.append('id', id);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        if (typeof Alert !== 'undefined') {
                            Alert.show('Error toggling status', 'error');
                        } else {
                            alert('Error toggling status');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error toggling status', 'error');
                    } else {
                        alert('Error toggling status');
                    }
                });
        }

        function toggleFeatured(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_featured');
            formData.append('id', id);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        if (typeof Alert !== 'undefined') {
                            Alert.show('Error toggling featured status', 'error');
                        } else {
                            alert('Error toggling featured status');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error toggling featured status', 'error');
                    } else {
                        alert('Error toggling featured status');
                    }
                });
        }

        function toggleUpcoming(id) {
            const formData = new FormData();
            formData.append('action', 'toggle_upcoming');
            formData.append('id', id);

            fetch(window.location.href, {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (response.ok) {
                        window.location.reload();
                    } else {
                        if (typeof Alert !== 'undefined') {
                            Alert.show('Error toggling upcoming status', 'error');
                        } else {
                            alert('Error toggling upcoming status');
                        }
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    if (typeof Alert !== 'undefined') {
                        Alert.show('Error toggling upcoming status', 'error');
                    } else {
                        alert('Error toggling upcoming status');
                    }
                });
        }

        function previewModalImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();

                reader.onload = function(e) {
                    document.getElementById('imagePreview').src = e.target.result;
                    document.getElementById('imageFileName').textContent = input.files[0].name;
                    document.getElementById('imagePreviewContainer').style.display = 'block';
                };

                reader.readAsDataURL(input.files[0]);
            }
        }

        function previewModalVideo(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                document.getElementById('videoFileName').textContent = file.name + ' (' + formatBytes(file.size) + ')';
                document.getElementById('videoPreviewContainer').style.display = 'block';
            }
        }

        function formatBytes(bytes) {
            const units = ['B', 'KB', 'MB', 'GB'];
            let size = bytes;
            let unitIndex = 0;

            while (size >= 1024 && unitIndex < units.length - 1) {
                size /= 1024;
                unitIndex++;
            }

            return size.toFixed(2) + ' ' + units[unitIndex];
        }

        // ── Facebook event share modal ────────────────────────────────────────
        window._fbEventDefaults = {
            baseUrl: <?php echo json_encode(rtrim(defined('BASE_URL') ? BASE_URL : '', '/')); ?>,
            hashtags: <?php echo json_encode(getSetting('facebook_default_hashtags', '#hotel #events')); ?>
        };

        var _fbEventId = 0;
        var _fbEventHasImage = false;

        function openFbEventShareModal(eventId, eventTitle, imagePath, eventDate) {
            _fbEventId = eventId;
            _fbEventHasImage = (typeof imagePath === 'string' && imagePath !== '');
            var modal = document.getElementById('fbEventShareModal');
            if (!modal) return;
            document.getElementById('fbEventShareTitle').textContent = 'Post "' + eventTitle + '" to Facebook';

            var d = window._fbEventDefaults || {};
            var caption = eventTitle + '\n';
            if (eventDate) {
                try {
                    var dt = new Date(eventDate);
                    caption += 'Date: ' + dt.toLocaleDateString('en-MW', {
                        weekday: 'long',
                        year: 'numeric',
                        month: 'long',
                        day: 'numeric'
                    }) + '\n';
                } catch (e) {}
            }
            caption += '\nFull details: ' + (d.baseUrl || '') + '/events.php#event-' + eventId + '\n\n' + (d.hashtags || '');
            document.getElementById('fbEventShareCaption').value = caption;

            var imgRow = document.getElementById('fbEventIncludeImageRow');
            if (imgRow) imgRow.style.display = _fbEventHasImage ? 'flex' : 'none';
            document.getElementById('fbEventIncludeImage').checked = _fbEventHasImage;
            document.getElementById('fbEventShareFeedback').className = 'admin-modal-feedback';
            document.getElementById('fbEventShareFeedback').innerHTML = '';
            modal.style.display = 'flex';
        }

        function closeFbEventShareModal() {
            var modal = document.getElementById('fbEventShareModal');
            if (modal) modal.style.display = 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            var submitBtn = document.getElementById('fbEventShareSubmitBtn');
            if (!submitBtn) return;
            submitBtn.addEventListener('click', function() {
                var caption = (document.getElementById('fbEventShareCaption').value || '').trim();
                if (!caption) {
                    var fb = document.getElementById('fbEventShareFeedback');
                    fb.className = 'admin-modal-feedback admin-modal-feedback--error visible';
                    fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Please enter a caption.';
                    return;
                }
                submitBtn.disabled = true;
                var origHtml = submitBtn.innerHTML;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting…';
                var fb = document.getElementById('fbEventShareFeedback');
                fb.className = 'admin-modal-feedback';
                fb.innerHTML = '';

                var formData = new FormData();
                formData.append('csrf_token', document.querySelector('input[name="csrf_token"]') ?
                    document.querySelector('input[name="csrf_token"]').value :
                    (document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').getAttribute('content') : ''));
                formData.append('type', 'event');
                formData.append('id', String(_fbEventId));
                formData.append('message', caption);
                formData.append('include_image', document.getElementById('fbEventIncludeImage').checked ? '1' : '0');

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
                        fb.innerHTML = '<i class="fas fa-exclamation-circle"></i> Network error — please try again.';
                    });
            });
        });
    </script>

    <!-- Facebook Event Share Modal -->
    <div class="modal-overlay" id="fbEventShareModal" style="display:none;" onclick="if(event.target===this)closeFbEventShareModal()">
        <div class="modal-content" style="max-width:520px;">
            <div class="modal-header" style="border-top:4px solid #1877F2;">
                <h3 id="fbEventShareTitle" style="color:#1877F2;"><i class="fab fa-facebook-f"></i> Post to Facebook</h3>
                <button class="modal-close" type="button" onclick="closeFbEventShareModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="fbEventShareCaption"><strong>Caption</strong> <small>(edit before posting)</small></label>
                    <textarea id="fbEventShareCaption" class="fb-caption-preview" rows="6"></textarea>
                </div>
                <div class="fb-include-image-row" id="fbEventIncludeImageRow" style="display:none;">
                    <input type="checkbox" id="fbEventIncludeImage" value="1" checked>
                    <label for="fbEventIncludeImage">Include event image in post</label>
                </div>
                <div id="fbEventShareFeedback" class="admin-modal-feedback" style="margin-top:12px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn fb-btn" id="fbEventShareSubmitBtn">
                    <i class="fab fa-facebook-f"></i> Post to Facebook Page
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeFbEventShareModal()">Cancel</button>
            </div>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>

