<?php

/**
 * Page Management
 * Enable, disable, and reorder public website pages via the admin panel.
 * Pages can NOT be deleted here — only directly in the database.
 */

require_once 'admin-init.php';
require_once '../includes/alert.php';
require_once 'includes/admin-modal.php';

$user = $user ?? ['id' => 0];
$csrf_token = $csrf_token ?? generateCsrfToken();
$site_name = $site_name ?? getSetting('site_name', 'Hotel');

if (!hasPermission((int)$user['id'], 'pages')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$message = '';
$error   = '';

function normalizePageKey(string $value): string
{
    return preg_replace('/[^a-z0-9_-]/', '', strtolower(trim($value)));
}

function normalizePageFilePath(string $value): string
{
    $value = trim(str_replace('\\', '/', $value));
    $value = ltrim($value, '/');
    $value = preg_replace('/\s+/', '', $value);
    return preg_replace('/\.\.+/', '.', $value);
}

function normalizePageIcon(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return 'fa-file';
    }
    return preg_match('/^[a-z0-9\- ]+$/i', $value) ? $value : 'fa-file';
}

function isValidPageFilePath(string $value): bool
{
    if ($value === '' || strlen($value) > 255) {
        return false;
    }
    if (strpos($value, '..') !== false || strpos($value, ':') !== false) {
        return false;
    }
    return (bool)preg_match('/^[a-zA-Z0-9._\/-]+$/', $value);
}

function ensureSitePagesTable(PDO $pdo): bool
{
    $existsStmt = $pdo->query("SHOW TABLES LIKE 'site_pages'");
    $alreadyExisted = $existsStmt && $existsStmt->rowCount() > 0;

    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS site_pages (\n            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n            page_key VARCHAR(50) NOT NULL COMMENT 'Unique slug, e.g. home, rooms, restaurant',\n            title VARCHAR(100) NOT NULL COMMENT 'Display name in navigation',\n            file_path VARCHAR(100) NOT NULL COMMENT 'PHP file, e.g. rooms-gallery.php',\n            icon VARCHAR(50) DEFAULT 'fa-file' COMMENT 'Font Awesome icon class',\n            nav_position INT DEFAULT 0 COMMENT 'Order in navigation (lower = first)',\n            show_in_nav TINYINT(1) DEFAULT 1 COMMENT '1 = visible in nav, 0 = hidden from nav but page still accessible',\n            is_enabled TINYINT(1) DEFAULT 1 COMMENT '1 = page accessible, 0 = disabled',\n            requires_auth TINYINT(1) DEFAULT 0,\n            description VARCHAR(255) DEFAULT NULL,\n            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n            UNIQUE KEY page_key (page_key),\n            UNIQUE KEY uniq_site_pages_file_path (file_path),\n            INDEX idx_enabled_nav (is_enabled, show_in_nav, nav_position)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");

    $defaults = [
        ['home', 'Home', 'index.php', 'fa-home', 10, 1, 1, 'Main landing page'],
        ['rooms', 'Rooms', 'rooms-gallery.php', 'fa-bed', 20, 1, 1, 'Room gallery and listings'],
        ['rooms-showcase', 'Rooms Showcase', 'rooms-showcase.php', 'fa-images', 25, 0, 1, 'Alternate room showcase layout'],
        ['restaurant', 'Restaurant', 'restaurant.php', 'fa-utensils', 30, 1, 1, 'Restaurant and menu'],
        ['gym', 'Gym', 'gym.php', 'fa-dumbbell', 40, 1, 1, 'Gym and fitness centre'],
        ['gym-schedule', 'Gym Schedule', 'gym-schedule.php', 'fa-calendar-day', 45, 1, 1, 'Gym class timetable and slot booking'],
        ['conference', 'Conference', 'conference.php', 'fa-briefcase', 50, 1, 1, 'Conference facilities'],
        ['events', 'Events', 'events.php', 'fa-calendar-alt', 60, 1, 1, 'Hotel events'],
        ['guest-services', 'Guest Services', 'guest-services.php', 'fa-concierge-bell', 80, 0, 1, 'Guest services page'],
        ['contact-us', 'Contact Us', 'contact-us.php', 'fa-envelope', 90, 0, 1, 'Contact page'],
        ['privacy-policy', 'Privacy Policy', 'privacy-policy.php', 'fa-user-shield', 95, 0, 1, 'Privacy policy page'],
        ['booking-lookup', 'Booking Lookup', 'booking-lookup.php', 'fa-search', 96, 0, 1, 'Guest booking lookup / manage'],
        ['booking', 'Book Now', 'booking.php', 'fa-calendar-check', 100, 1, 1, 'Booking page CTA'],
    ];

    $stmt = $pdo->prepare("\n        INSERT INTO site_pages (page_key, title, file_path, icon, nav_position, show_in_nav, is_enabled, requires_auth, description)\n        VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)\n        ON DUPLICATE KEY UPDATE page_key = page_key\n    ");
    foreach ($defaults as $page) {
        $stmt->execute($page);
    }

    return !$alreadyExisted;
}

// ─── Ensure site_pages table exists ───────────────────────────────────
try {
    $createdSitePages = ensureSitePagesTable($pdo);
    if ($createdSitePages) {
        $message = 'Page management table created and seeded with default pages.';
        rh_log_event('page_management', 'info', 'site_pages table created and seeded');
    }
} catch (PDOException $e) {
    $error = 'Could not initialize Page Management table: ' . $e->getMessage();
    rh_log_event('page_management', 'error', 'Failed to initialize site_pages table', ['error' => $e->getMessage()]);
}

// ─── Handle POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        try {
            switch ($action) {

                // Toggle is_enabled
                case 'toggle_enabled':
                    $id = (int)($_POST['page_id'] ?? 0);
                    if ($id <= 0) {
                        $error = 'Invalid page selected.';
                        break;
                    }

                    $pgRow = $pdo->prepare("SELECT page_key, title, file_path, is_enabled FROM site_pages WHERE id = ?");
                    $pgRow->execute([$id]);
                    $pgRow = $pgRow->fetch(PDO::FETCH_ASSOC);
                    if (!$pgRow) {
                        $error = 'Page not found.';
                        break;
                    }

                    // Core pages are in page-guard's skip list — disabling them has no effect
                    $corePaths = ['index.php', 'booking-confirmation.php', 'review-confirmation.php', 'submit-review.php'];
                    if (in_array($pgRow['file_path'], $corePaths, true) && (int)$pgRow['is_enabled'] === 1) {
                        $error = 'This page is required by the system and cannot be disabled.';
                        break;
                    }

                    $stmt = $pdo->prepare("UPDATE site_pages SET is_enabled = NOT is_enabled WHERE id = ?");
                    $stmt->execute([$id]);
                    $newState = (int)$pgRow['is_enabled'] === 1 ? 'disabled' : 'enabled';
                    $message = 'Page "' . htmlspecialchars($pgRow['title']) . '" ' . $newState . '.';
                    rh_log_event('page_management', 'info', 'Page enabled/disabled', [
                        'page_key' => $pgRow['page_key'],
                        'new_state' => $newState,
                        'by' => $user['username'] ?? 'unknown',
                    ]);
                    break;

                // Toggle show_in_nav
                case 'toggle_nav':
                    $id = (int)($_POST['page_id'] ?? 0);
                    if ($id <= 0) {
                        $error = 'Invalid page selected.';
                        break;
                    }

                    $navRow = $pdo->prepare("SELECT page_key, title, show_in_nav FROM site_pages WHERE id = ?");
                    $navRow->execute([$id]);
                    $navRow = $navRow->fetch(PDO::FETCH_ASSOC);
                    if (!$navRow) {
                        $error = 'Page not found.';
                        break;
                    }

                    $stmt = $pdo->prepare("UPDATE site_pages SET show_in_nav = NOT show_in_nav WHERE id = ?");
                    $stmt->execute([$id]);
                    $navState = (int)$navRow['show_in_nav'] === 1 ? 'hidden from nav' : 'shown in nav';
                    $message = 'Page "' . htmlspecialchars($navRow['title']) . '" ' . $navState . '.';
                    rh_log_event('page_management', 'info', 'Page nav visibility toggled', [
                        'page_key' => $navRow['page_key'],
                        'new_state' => $navState,
                        'by' => $user['username'] ?? 'unknown',
                    ]);
                    break;

                // Save nav order (from sortable list)
                case 'save_order':
                    $order = json_decode($_POST['page_order'] ?? '[]', true);
                    if (is_array($order)) {
                        $order = array_values(array_unique(array_map('intval', $order)));
                        if (empty($order)) {
                            $error = 'Invalid page order.';
                            break;
                        }

                        $placeholders = implode(',', array_fill(0, count($order), '?'));
                        $checkStmt = $pdo->prepare("SELECT id FROM site_pages WHERE id IN ($placeholders)");
                        $checkStmt->execute($order);
                        $validIds = array_map('intval', $checkStmt->fetchAll(PDO::FETCH_COLUMN));
                        if (count($validIds) !== count($order)) {
                            $error = 'Invalid page order payload.';
                            break;
                        }
                        $validMap = array_flip($validIds);

                        $stmt = $pdo->prepare("UPDATE site_pages SET nav_position = ? WHERE id = ?");
                        foreach ($order as $pos => $id) {
                            if (!isset($validMap[$id])) {
                                continue;
                            }
                            $stmt->execute([($pos + 1) * 10, (int)$id]);
                        }
                        $message = 'Navigation order saved.';
                    } else {
                        $error = 'Invalid page order payload.';
                    }
                    break;

                // Add new page
                case 'add_page':
                    $page_key     = normalizePageKey($_POST['page_key'] ?? '');
                    $title        = trim($_POST['title'] ?? '');
                    $file_path    = normalizePageFilePath($_POST['file_path'] ?? '');
                    $icon         = normalizePageIcon($_POST['icon'] ?? 'fa-file');
                    $desc         = trim($_POST['description'] ?? '');
                    $page_heading = trim($_POST['page_heading'] ?? '') ?: $title;
                    $create_file  = !empty($_POST['create_file']);

                    if (!$page_key || !$title || !$file_path) {
                        $error = 'Page key, title, and file path are required.';
                    } elseif (!isValidPageFilePath($file_path)) {
                        $error = 'Invalid file path.';
                    } elseif (strpos($file_path, '/') !== false) {
                        // Only allow root-level files — no subdirectory creation
                        $error = 'New pages must be root-level PHP files (e.g. spa.php). Subdirectories are not supported.';
                    } else {
                        // Check for duplicate key
                        $chk = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE page_key = ?");
                        $chk->execute([$page_key]);
                        if ($chk->fetchColumn() > 0) {
                            $error = 'A page with that key already exists.';
                        } else {
                            $pathChk = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE file_path = ?");
                            $pathChk->execute([$file_path]);
                            if ($pathChk->fetchColumn() > 0) {
                                $error = 'A page with that file path already exists.';
                                break;
                            }

                            // Optionally scaffold the PHP file
                            if ($create_file) {
                                $target = realpath(__DIR__ . '/../') . DIRECTORY_SEPARATOR . basename($file_path);
                                if (file_exists($target)) {
                                    $error = 'A file named ' . htmlspecialchars($file_path) . ' already exists on disk. Uncheck "Create PHP file" or use a different file name.';
                                    break;
                                }

                                $safeTitle   = addslashes($title);
                                $safeHeading = htmlspecialchars($page_heading, ENT_QUOTES, 'UTF-8');
                                $safeKey     = $page_key;
                                $scaffold = <<<PHP
<?php
require_once 'config/database.php';
require_once 'config/base-url.php';
require_once 'includes/page-guard.php';
require_once 'includes/section-headers.php';

\$site_name = getSetting('site_name');

\$seo_data = [
    'title'       => '{$safeTitle} — ' . \$site_name,
    'description' => '',
    'image'       => '',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <?php require_once 'includes/seo-meta.php'; ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="js/session-handler.js" defer></script>
</head>
<body>
    <?php require_once 'includes/loader.php'; ?>
    <?php require_once 'includes/header.php'; ?>
    <main id="main-content">

        <!-- Hero Section -->
        <section class="editorial-section" style="padding: clamp(80px,10vw,140px) 0 clamp(40px,6vw,80px);">
            <div class="container" style="max-width:900px; margin:0 auto; padding:0 clamp(16px,5vw,48px); text-align:center;">
                <span class="section-header__label">{$safeKey}</span>
                <h1 class="section-header__title" style="font-size:clamp(2rem,5vw,3.5rem); margin-top:var(--space-3);">{$safeHeading}</h1>
            </div>
        </section>

        <!-- Main Content Section -->
        <section class="editorial-section" data-lazy-reveal>
            <div class="container" style="max-width:900px; margin:0 auto; padding:0 clamp(16px,5vw,48px) clamp(40px,6vw,80px);">
                <p style="font-family:var(--font-body); font-size:clamp(1rem,2vw,1.15rem); color:var(--color-text-secondary); line-height:1.8;">
                    Content for this page goes here.
                </p>
            </div>
        </section>

    </main>
    <?php require_once 'includes/footer.php'; ?>
    <?php require_once 'includes/modal.php'; ?>
    <script src="js/page-transitions.js" defer></script>
    <script src="js/scroll-reveal.js" defer></script>
</body>
</html>
PHP;
                                if (file_put_contents($target, $scaffold) === false) {
                                    $error = 'Could not write file to disk. Check server write permissions on the webroot.';
                                    break;
                                }
                            }

                            $maxPos = $pdo->query("SELECT COALESCE(MAX(nav_position), 0) FROM site_pages")->fetchColumn();
                            $stmt = $pdo->prepare("
                                INSERT INTO site_pages (page_key, title, file_path, icon, nav_position, show_in_nav, is_enabled, description)
                                VALUES (?, ?, ?, ?, ?, 1, 1, ?)
                            ");
                            $stmt->execute([$page_key, $title, $file_path, $icon, $maxPos + 10, $desc]);
                            $fileNote = $create_file ? ' PHP file created.' : '';
                            $message = "Page \"$title\" added successfully.$fileNote";
                            rh_log_event('page_management', 'info', 'New page added', [
                                'page_key'    => $page_key,
                                'file_path'   => $file_path,
                                'file_created' => $create_file,
                                'by'          => $user['username'] ?? 'unknown',
                            ]);
                        }
                    }
                    break;

                // Edit page details
                case 'edit_page':
                    $id        = (int)($_POST['page_id'] ?? 0);
                    $title     = trim($_POST['title'] ?? '');
                    $file_path = normalizePageFilePath($_POST['file_path'] ?? '');
                    $icon      = normalizePageIcon($_POST['icon'] ?? 'fa-file');
                    $desc      = trim($_POST['description'] ?? '');

                    if ($id <= 0 || !$title || !$file_path) {
                        $error = 'Title and file path are required.';
                    } elseif (!isValidPageFilePath($file_path)) {
                        $error = 'Invalid file path.';
                    } else {
                        $exists = $pdo->prepare("SELECT id FROM site_pages WHERE id = ?");
                        $exists->execute([$id]);
                        if (!$exists->fetchColumn()) {
                            $error = 'Page not found.';
                            break;
                        }

                        $dup = $pdo->prepare("SELECT COUNT(*) FROM site_pages WHERE file_path = ? AND id <> ?");
                        $dup->execute([$file_path, $id]);
                        if ($dup->fetchColumn() > 0) {
                            $error = 'Another page already uses that file path.';
                            break;
                        }

                        $stmt = $pdo->prepare("
                            UPDATE site_pages SET title = ?, file_path = ?, icon = ?, description = ? WHERE id = ?
                        ");
                        $stmt->execute([$title, $file_path, $icon, $desc, $id]);
                        $message = $stmt->rowCount() > 0 ? "Page \"$title\" updated." : 'No page was updated.';
                    }
                    break;

                default:
                    $error = 'Unsupported action requested.';
                    break;
            }

            // Clear page cache so nav picks up changes immediately
            if ($message && file_exists(__DIR__ . '/../config/cache.php')) {
                require_once __DIR__ . '/../config/cache.php';
                require_once __DIR__ . '/../config/page-cache.php';
                if (function_exists('invalidatePageCache')) {
                    invalidatePageCache();
                } elseif (function_exists('clearPageCache')) {
                    clearPageCache();
                }
                rh_log_event('page_management', 'info', 'Page management action completed', ['action' => $action, 'message' => $message]);
            }
        } catch (PDOException $ex) {
            $error = 'Database error: ' . $ex->getMessage();
            error_log("Page management error: " . $ex->getMessage());
            rh_log_event('page_management', 'error', 'Page management action failed', ['action' => $action, 'error' => $ex->getMessage()]);
        }
    }
}

// ─── Fetch all pages ──────────────────────────────────────────────────
try {
    $pages = $pdo->query("SELECT * FROM site_pages ORDER BY nav_position ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $pages = [];
    $error = 'Could not load pages. The site_pages table may not exist yet.';
    rh_log_event('page_management', 'error', 'Failed to load site_pages rows', ['error' => $e->getMessage()]);
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Management - <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/page-management.css?v=<?php echo @filemtime(__DIR__ . '/css/page-management.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content page-management-page">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-file-alt"></i> Page Management
            </h2>
            <p class="text-muted">Enable, disable, and reorder public website pages</p>
        </div>

        <?php if ($message): ?>
            <?php showAlert($message, 'success'); ?>
        <?php endif; ?>
        <?php if ($error): ?>
            <?php showAlert($error, 'error'); ?>
        <?php endif; ?>

        <!-- Preset awareness note -->
        <div class="security-note" style="border-left-color:#8B7355;background:#fdf8f0;">
            <i class="fas fa-puzzle-piece" style="color:#8B7355;"></i>
            <div>
                <strong>Modules &amp; presets:</strong>
                A page only goes live when <em>both</em> its status here is <strong>Enabled</strong> <em>and</em> its business module is switched on for the active preset.
                Pages tagged <span class="badge badge-module-off" style="vertical-align:middle;"><i class="fas fa-ban"></i> preset off</span> stay hidden until you enable their module in
                <a href="module-settings.php">Module Settings</a> — enabling them here alone won't surface them.
            </div>
        </div>

        <!-- Security Note -->
        <div class="security-note">
            <i class="fas fa-shield-alt"></i>
            <div>
                <strong>Security Notice:</strong>
                Pages cannot be deleted from this panel. To permanently remove a page, delete the row directly from the <code>site_pages</code> database table. This protects against accidental removal.
            </div>
        </div>

        <!-- Pages Table -->
        <div class="pm-section">
            <h2><i class="fas fa-list-ul"></i> Website Pages</h2>

            <?php if (empty($pages)): ?>
                <p style="color:#666; text-align:center; padding:30px 0;">No pages found. Add your first page below.</p>
            <?php else: ?>

                <form method="POST" id="orderForm">
                    <input type="hidden" name="action" value="save_order">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="page_order" id="pageOrderInput">
                </form>

                <div style="overflow-x: auto;">
                    <table class="pm-table" id="pagesTable">
                        <thead>
                            <tr>
                                <th style="width:40px"></th>
                                <th>Page</th>
                                <th>Module / Preset</th>
                                <th>Status</th>
                                <th>Navigation</th>
                                <th>Order</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pagesTbody">
                            <?php foreach ($pages as $page):
                                $pgFeature = function_exists('rh_front_page_feature')
                                    ? rh_front_page_feature($page['file_path'])
                                    : null;
                                $pgModuleOff = $pgFeature !== null && !$pgFeature['enabled'];
                            ?>
                                <tr data-id="<?php echo $page['id']; ?>" class="<?php echo $pgModuleOff ? 'pm-row-muted' : ''; ?>">
                                    <td data-label="">
                                        <span class="drag-handle" title="Drag to reorder"><i class="fas fa-grip-vertical"></i></span>
                                    </td>
                                    <td data-label="Page">
                                        <div class="page-info">
                                            <span class="page-icon-preview"><i class="fas <?php echo htmlspecialchars($page['icon']); ?>"></i></span>
                                            <div>
                                                <div class="page-title"><?php echo htmlspecialchars($page['title']); ?></div>
                                                <div class="page-file"><?php echo htmlspecialchars($page['file_path']); ?></div>
                                                <?php if ($page['description']): ?>
                                                    <div style="font-size:12px;color:#888;margin-top:2px;"><?php echo htmlspecialchars($page['description']); ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td data-label="Module / Preset">
                                        <?php if ($pgFeature === null): ?>
                                            <span class="badge badge-core" title="Shown on every business preset"><i class="fas fa-globe"></i> Always on</span>
                                        <?php elseif ($pgFeature['enabled']): ?>
                                            <span class="badge badge-module-on" title="The '<?php echo htmlspecialchars($pgFeature['label']); ?>' module is enabled for this preset">
                                                <i class="fas fa-puzzle-piece"></i> <?php echo htmlspecialchars($pgFeature['label']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-module-off" title="The '<?php echo htmlspecialchars($pgFeature['label']); ?>' module is OFF for the active preset, so this page stays hidden regardless of its status.">
                                                <i class="fas fa-ban"></i> <?php echo htmlspecialchars($pgFeature['label']); ?> · preset off
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Status">
                                        <?php if ($pgModuleOff): ?>
                                            <span class="badge badge-disabled" title="Hidden because its module is off in Module Settings, even though the page itself is set to <?php echo (int)$page['is_enabled'] === 1 ? 'enabled' : 'disabled'; ?>.">
                                                <i class="fas fa-eye-slash"></i> Hidden by preset
                                            </span>
                                        <?php elseif ($page['is_enabled']): ?>
                                            <span class="badge badge-enabled"><i class="fas fa-check-circle"></i> Live</span>
                                        <?php else: ?>
                                            <span class="badge badge-disabled"><i class="fas fa-times-circle"></i> Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Navigation">
                                        <?php if ($page['show_in_nav']): ?>
                                            <span class="badge badge-nav-yes"><i class="fas fa-eye"></i> Visible</span>
                                        <?php else: ?>
                                            <span class="badge badge-nav-no"><i class="fas fa-eye-slash"></i> Hidden</span>
                                        <?php endif; ?>
                                    </td>
                                    <td data-label="Order">
                                        <?php echo (int)$page['nav_position']; ?>
                                    </td>
                                    <td data-label="Actions">
                                        <div class="action-group">
                                            <!-- Toggle Enable/Disable -->
                                            <form method="POST" style="display:inline;" class="form-toggle-enabled">
                                                <input type="hidden" name="action" value="toggle_enabled">
                                                <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <?php if ($page['is_enabled']): ?>
                                                    <button type="button" class="btn-toggle btn-disable" title="Disable this page"
                                                        onclick="openDisableConfirm(<?php echo htmlspecialchars(json_encode($page['title']), ENT_QUOTES, 'UTF-8'); ?>, this.closest('form'))">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button type="submit" class="btn-toggle btn-enable" title="Enable this page">
                                                        <i class="fas fa-power-off"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </form>

                                            <!-- Toggle Nav Visibility -->
                                            <form method="POST" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_nav">
                                                <input type="hidden" name="page_id" value="<?php echo $page['id']; ?>">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <button type="submit" class="btn-toggle btn-nav-toggle" title="<?php echo $page['show_in_nav'] ? 'Hide from navigation' : 'Show in navigation'; ?>">
                                                    <i class="fas <?php echo $page['show_in_nav'] ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                                </button>
                                            </form>

                                            <!-- Edit -->
                                            <button type="button" class="btn-toggle btn-edit" title="Edit page details"
                                                onclick='openEditModal(<?php echo htmlspecialchars(json_encode($page), ENT_QUOTES, "UTF-8"); ?>)'>
                                                <i class="fas fa-pencil-alt"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div style="margin-top: 16px; display: flex; gap: 12px; align-items: center;">
                    <button type="button" class="btn-save-order" onclick="saveOrder()">
                        <i class="fas fa-sort-amount-down"></i> Save Order
                    </button>
                    <span style="font-size: 13px; color: #888;">Drag rows to reorder, then click Save Order</span>
                </div>

            <?php endif; ?>
        </div>

        <!-- Add New Page -->
        <div class="pm-section">
            <h2><i class="fas fa-plus-circle"></i> Add New Page</h2>

            <form method="POST">
                <input type="hidden" name="action" value="add_page">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                <div class="add-page-grid">
                    <div>
                        <label for="page_key">Page Key (slug) <span style="color:#dc3545">*</span></label>
                        <input type="text" id="page_key" name="page_key" placeholder="e.g. spa" required
                            pattern="[a-z0-9_-]+" title="Lowercase letters, numbers, hyphens, and underscores only"
                            oninput="autoFillFilePath(this.value)">
                    </div>
                    <div>
                        <label for="add_title">Nav Title <span style="color:#dc3545">*</span></label>
                        <input type="text" id="add_title" name="title" placeholder="e.g. Spa & Wellness" required>
                    </div>
                    <div>
                        <label for="add_file_path">File Path <span style="color:#dc3545">*</span></label>
                        <input type="text" id="add_file_path" name="file_path" placeholder="e.g. spa.php" required>
                    </div>
                    <div>
                        <label for="add_icon">Icon (Font Awesome)</label>
                        <input type="text" id="add_icon" name="icon" placeholder="e.g. fa-spa" value="fa-file">
                    </div>
                    <div>
                        <label for="add_page_heading">Page Heading</label>
                        <input type="text" id="add_page_heading" name="page_heading" placeholder="e.g. Spa & Wellness Centre">
                        <p style="font-size:12px;color:#888;margin:4px 0 0;">Used as the &lt;h1&gt; if you generate a file.</p>
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label for="add_description">Description</label>
                        <input type="text" id="add_description" name="description" placeholder="Short description for admin reference">
                    </div>
                    <div style="grid-column: 1 / -1;">
                        <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                            <input type="checkbox" name="create_file" value="1" id="chk_create_file" checked>
                            <span>Generate a starter PHP file on disk</span>
                        </label>
                        <p style="font-size:12px;color:#888;margin:4px 0 0;padding-left:24px;">Creates a Japandi-styled skeleton page ready to customise. Uncheck if the file already exists.</p>
                    </div>
                </div>

                <button type="submit" class="btn-submit">
                    <i class="fas fa-plus"></i> Add Page
                </button>
            </form>
        </div>
    </div>

    <!-- Edit Modal -->
    <?php renderAdminModalStart('editPageModal', 'Edit Page', 'page-management-modal-content'); ?>
    <form method="POST" id="editForm">
        <input type="hidden" name="action" value="edit_page">
        <input type="hidden" name="page_id" id="edit_page_id">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="form-group">
            <label>Page Key</label>
            <input type="text" id="edit_page_key" disabled style="background:#f0f0f0; cursor:not-allowed;">
        </div>
        <div class="form-group">
            <label for="edit_title">Nav Title <span style="color:#dc3545">*</span></label>
            <input type="text" id="edit_title" name="title" required>
        </div>
        <div class="form-group">
            <label for="edit_file_path">File Path <span style="color:#dc3545">*</span></label>
            <input type="text" id="edit_file_path" name="file_path" required>
        </div>
        <div class="form-group">
            <label for="edit_icon">Icon (Font Awesome)</label>
            <input type="text" id="edit_icon" name="icon">
        </div>
        <div class="form-group">
            <label for="edit_description">Description</label>
            <textarea id="edit_description" name="description" rows="2"></textarea>
        </div>

        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
            <button type="submit" class="btn-submit"><i class="fas fa-save"></i> Save Changes</button>
        </div>
    </form>
    <?php renderAdminModalEnd(); ?>

    <!-- Disable Confirm Modal -->
    <?php renderAdminModalStart('confirmDisableModal', 'Disable Page'); ?>
    <p>Are you sure you want to disable <strong id="confirmDisablePageName"></strong>?</p>
    <p style="color:#888;font-size:13px;">Visitors will be redirected to the home page until it is re-enabled.</p>
    <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeAdminModal('confirmDisableModal')">Cancel</button>
        <button type="button" class="btn-submit" style="background:var(--color-danger,#dc3545);" onclick="execDisableConfirm()">
            <i class="fas fa-power-off"></i> Disable Page
        </button>
    </div>
    <?php renderAdminModalEnd(); ?>

    <?php renderAdminModalScript(); ?>

    <script>
        // ── Edit Modal ─────────────────────────────────────────
        function openEditModal(page) {
            document.getElementById('edit_page_id').value = page.id;
            document.getElementById('edit_page_key').value = page.page_key;
            document.getElementById('edit_title').value = page.title;
            document.getElementById('edit_file_path').value = page.file_path;
            document.getElementById('edit_icon').value = page.icon || 'fa-file';
            document.getElementById('edit_description').value = page.description || '';
            openAdminModal('editPageModal');
        }

        function closeEditModal() {
            closeAdminModal('editPageModal');
        }
        bindAdminModal('editPageModal');

        // ── Drag & Drop Reorder ────────────────────────────────
        (function() {
            var tbody = document.getElementById('pagesTbody');
            if (!tbody) return;

            var dragging = null;

            tbody.querySelectorAll('.drag-handle').forEach(function(handle) {
                var row = handle.closest('tr');
                row.setAttribute('draggable', 'true');

                row.addEventListener('dragstart', function(e) {
                    dragging = this;
                    this.style.opacity = '0.4';
                    e.dataTransfer.effectAllowed = 'move';
                });

                row.addEventListener('dragend', function() {
                    this.style.opacity = '1';
                    dragging = null;
                    tbody.querySelectorAll('tr').forEach(function(r) {
                        r.style.borderTop = '';
                    });
                });

                row.addEventListener('dragover', function(e) {
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                    if (dragging && dragging !== this) {
                        this.style.borderTop = '3px solid var(--gold, #8B7355)';
                    }
                });

                row.addEventListener('dragleave', function() {
                    this.style.borderTop = '';
                });

                row.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.style.borderTop = '';
                    if (dragging && dragging !== this) {
                        tbody.insertBefore(dragging, this);
                    }
                });
            });
        })();

        // ── Auto-fill file path from page key ─────────────────
        var _filePathTouched = false;
        document.getElementById('add_file_path').addEventListener('input', function() {
            _filePathTouched = true;
        });

        function autoFillFilePath(slug) {
            if (_filePathTouched) return;
            var fp = document.getElementById('add_file_path');
            if (fp) {
                fp.value = slug.replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '') + '.php';
            }
        }

        function saveOrder() {
            var rows = document.querySelectorAll('#pagesTbody tr');
            var order = [];
            rows.forEach(function(row) {
                order.push(row.dataset.id);
            });
            document.getElementById('pageOrderInput').value = JSON.stringify(order);
            document.getElementById('orderForm').submit();
        }

        // ── Disable Confirm Modal ──────────────────────────────
        var _pendingDisableForm = null;

        function openDisableConfirm(pageTitle, formEl) {
            _pendingDisableForm = formEl;
            document.getElementById('confirmDisablePageName').textContent = '\u201c' + pageTitle + '\u201d';
            openAdminModal('confirmDisableModal');
        }

        function execDisableConfirm() {
            closeAdminModal('confirmDisableModal');
            if (_pendingDisableForm) {
                _pendingDisableForm.submit();
            }
        }
        bindAdminModal('confirmDisableModal');
    </script>
    <?php require_once 'includes/admin-footer.php'; ?>

