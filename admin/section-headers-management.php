<?php

/**
 * Section Headers Management
 * Admin interface for managing dynamic section headers
 */

require_once 'admin-init.php';
/** @var string $csrf_token */
require_once '../includes/alert.php';
require_once '../includes/section-headers.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

if (!hasPermission((int)$user['id'], 'section_headers') && !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$message = '';
$error = '';
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        header('Location: ' . basename($_SERVER['PHP_SELF']));
        exit;
    }
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_header') {
            $section_key = $_POST['section_key'] ?? '';
            $page = $_POST['page'] ?? '';
            $section_label = $_POST['section_label'] ?? '';
            $section_subtitle = $_POST['section_subtitle'] ?? '';
            $section_title = $_POST['section_title'] ?? '';
            $section_description = $_POST['section_description'] ?? '';
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($section_key) || empty($page)) {
                throw new Exception('Section key and page are required.');
            }

            $stmt = $pdo->prepare("
                UPDATE section_headers
                SET section_label = ?,
                    section_subtitle = ?,
                    section_title = ?,
                    section_description = ?,
                    is_active = ?,
                    updated_at = NOW()
                WHERE section_key = ? AND page = ?
            ");

            $stmt->execute([
                $section_label,
                $section_subtitle,
                $section_title,
                $section_description,
                $is_active,
                $section_key,
                $page
            ]);

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Section header updated successfully!';
            $success = true;
        } elseif ($action === 'toggle_active') {
            $section_key = $_POST['section_key'] ?? '';
            $page = $_POST['page'] ?? '';

            $stmt = $pdo->prepare("
                UPDATE section_headers
                SET is_active = NOT is_active,
                    updated_at = NOW()
                WHERE section_key = ? AND page = ?
            ");

            $stmt->execute([$section_key, $page]);

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Section header status updated!';
            $success = true;
        } elseif ($action === 'update_hero') {
            $hero_id          = (int)($_POST['hero_id'] ?? 0);
            $hero_title       = trim($_POST['hero_title'] ?? '');
            $hero_subtitle    = trim($_POST['hero_subtitle'] ?? '');
            $hero_description = trim($_POST['hero_description'] ?? '');
            $primary_cta_text = trim($_POST['primary_cta_text'] ?? '');
            $primary_cta_link = trim($_POST['primary_cta_link'] ?? '');
            $is_active        = isset($_POST['hero_is_active']) ? 1 : 0;

            if ($hero_id <= 0 || empty($hero_title)) {
                throw new Exception('Hero ID and title are required.');
            }

            $stmt = $pdo->prepare("
                UPDATE page_heroes
                SET hero_title         = ?,
                    hero_subtitle      = ?,
                    hero_description   = ?,
                    primary_cta_text   = ?,
                    primary_cta_link   = ?,
                    is_active          = ?,
                    updated_at         = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $hero_title,
                $hero_subtitle ?: null,
                $hero_description ?: null,
                $primary_cta_text ?: null,
                $primary_cta_link ?: null,
                $is_active,
                $hero_id,
            ]);

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Page hero text updated successfully!';
            $success = true;
        } elseif ($action === 'revert_defaults') {
            // Delete all current section headers
            $pdo->exec("DELETE FROM section_headers");

            // Re-insert all default section headers
            $defaults = [
                // Homepage sections
                ['home_rooms', 'index', 'Accommodations', 'Where Comfort Meets Luxury', 'Luxurious Rooms & Suites', 'Experience unmatched comfort in our meticulously designed rooms and suites', 1],
                ['home_facilities', 'index', 'Amenities', NULL, 'World-Class Facilities', 'Indulge in our premium facilities designed for your ultimate comfort', 2],
                ['home_testimonials', 'index', 'Reviews', NULL, 'What Our Guests Say', 'Hear from those who have experienced our exceptional hospitality', 3],
                ['booking_widget', 'index', 'Reserve', NULL, 'Begin Your Stay', 'Select your dates and preferences for a seamless luxury booking experience.', 4],
                // Hotel Gallery
                ['hotel_gallery', 'index', 'Visual Journey', 'Discover Our Story', 'Explore Our Hotel', 'Immerse yourself in the beauty and luxury of our hotel', 5],
                // Reviews (global)
                ['hotel_reviews', 'global', 'Guest Impressions', NULL, 'Stories from Our Guests', 'Hear from those who have experienced our exceptional hospitality', 1],
                // Restaurant
                ['restaurant_gallery', 'restaurant', 'Visual Journey', NULL, 'Our Dining Spaces', 'From elegant interiors to breathtaking views, every detail creates the perfect ambiance', 1],
                ['restaurant_menu', 'restaurant', 'Culinary Delights', 'A Symphony of Flavors', 'Our Menu', 'Discover our carefully curated selection of dishes and beverages', 2],
                // Gym
                ['gym_wellness', 'gym', 'Your Wellness Journey', 'Transform Your Life', 'Start Your Fitness Journey', 'Transform your body and mind with our state-of-the-art facilities', 1],
                ['gym_facilities', 'gym', 'What We Offer', NULL, 'Comprehensive Fitness Facilities', 'Everything you need for a complete wellness experience', 2],
                ['gym_classes', 'gym', 'Stay Active', NULL, 'Group Fitness Classes', 'Join our expert-led classes designed for all fitness levels', 3],
                ['gym_training', 'gym', 'One-on-One Coaching', NULL, 'Personal Training Programs', 'Achieve your fitness goals faster with personalized guidance from our certified trainers', 4],
                ['gym_packages', 'gym', 'Exclusive Offers', NULL, 'Wellness Packages', 'Comprehensive packages designed for optimal health and relaxation', 5],
                // Rooms showcase
                ['rooms_collection', 'rooms-showcase', 'Stay Collection', NULL, 'Pick Your Perfect Space', 'Suites and rooms crafted for business, romance, and family stays with direct booking flows', 1],
                // Conference
                ['conference_overview', 'conference', 'Our Meeting Spaces', NULL, 'Professional Conference Facilities', 'State-of-the-art venues for your business meetings and events', 1],
                // Events
                ['events_overview', 'events', 'Upcoming Events', NULL, 'Special Events & Occasions', 'Join us for memorable celebrations and special gatherings', 1],
                // Upcoming Events (homepage section)
                ['upcoming_events', 'index', "What's Happening", NULL, 'Upcoming Events', "Don't miss out on our carefully curated experiences and celebrations", 6]
            ];

            $stmt = $pdo->prepare("
                INSERT INTO section_headers
                (section_key, page, section_label, section_subtitle, section_title, section_description, display_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($defaults as $default) {
                $stmt->execute($default);
            }

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'All section headers have been reset to factory defaults!';
            $success = true;
        } elseif ($action === 'create_hero') {
            $page_slug_new  = trim(preg_replace('/[^a-z0-9\-]/', '', strtolower($_POST['new_page_slug'] ?? '')));
            $page_url_new   = trim($_POST['new_page_url'] ?? '');
            $hero_title_new = trim($_POST['new_hero_title'] ?? '');

            if (empty($page_slug_new) || empty($hero_title_new)) {
                throw new Exception('Page slug and hero title are required.');
            }

            // Check slug not already taken
            $chk = $pdo->prepare("SELECT id FROM page_heroes WHERE page_slug = ? LIMIT 1");
            $chk->execute([$page_slug_new]);
            if ($chk->fetchColumn()) {
                throw new Exception("A hero for slug '{$page_slug_new}' already exists. Edit it from the list below.");
            }

            $ins = $pdo->prepare("
                INSERT INTO page_heroes (page_slug, page_url, hero_title, hero_subtitle, hero_description, is_active, display_order)
                VALUES (?, ?, ?, NULL, NULL, 1, 99)
            ");
            $ins->execute([$page_slug_new, $page_url_new ?: '/' . $page_slug_new . '.php', $hero_title_new]);

            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = "Hero entry created for '{$page_slug_new}'. You can now edit it below.";
            $success = true;
        } elseif ($action === 'reset_single') {
            $section_key = $_POST['section_key'] ?? '';
            $page = $_POST['page'] ?? '';

            if (empty($section_key) || empty($page)) {
                throw new Exception('Section key and page are required.');
            }

            // Define all defaults
            $all_defaults = [
                ['home_rooms', 'index', 'Accommodations', 'Where Comfort Meets Luxury', 'Luxurious Rooms & Suites', 'Experience unmatched comfort in our meticulously designed rooms and suites', 1],
                ['home_facilities', 'index', 'Amenities', NULL, 'World-Class Facilities', 'Indulge in our premium facilities designed for your ultimate comfort', 2],
                ['home_testimonials', 'index', 'Reviews', NULL, 'What Our Guests Say', 'Hear from those who have experienced our exceptional hospitality', 3],
                ['booking_widget', 'index', 'Reserve', NULL, 'Begin Your Stay', 'Select your dates and preferences for a seamless luxury booking experience.', 4],
                ['hotel_gallery', 'index', 'Visual Journey', 'Discover Our Story', 'Explore Our Hotel', 'Immerse yourself in the beauty and luxury of our hotel', 5],
                ['hotel_reviews', 'global', 'Guest Impressions', NULL, 'Stories from Our Guests', 'Hear from those who have experienced our exceptional hospitality', 1],
                ['restaurant_gallery', 'restaurant', 'Visual Journey', NULL, 'Our Dining Spaces', 'From elegant interiors to breathtaking views, every detail creates the perfect ambiance', 1],
                ['restaurant_menu', 'restaurant', 'Culinary Delights', 'A Symphony of Flavors', 'Our Menu', 'Discover our carefully curated selection of dishes and beverages', 2],
                ['gym_wellness', 'gym', 'Your Wellness Journey', 'Transform Your Life', 'Start Your Fitness Journey', 'Transform your body and mind with our state-of-the-art facilities', 1],
                ['gym_facilities', 'gym', 'What We Offer', NULL, 'Comprehensive Fitness Facilities', 'Everything you need for a complete wellness experience', 2],
                ['gym_classes', 'gym', 'Stay Active', NULL, 'Group Fitness Classes', 'Join our expert-led classes designed for all fitness levels', 3],
                ['gym_training', 'gym', 'One-on-One Coaching', NULL, 'Personal Training Programs', 'Achieve your fitness goals faster with personalized guidance from our certified trainers', 4],
                ['gym_packages', 'gym', 'Exclusive Offers', NULL, 'Wellness Packages', 'Comprehensive packages designed for optimal health and relaxation', 5],
                ['rooms_collection', 'rooms-showcase', 'Stay Collection', NULL, 'Pick Your Perfect Space', 'Suites and rooms crafted for business, romance, and family stays with direct booking flows', 1],
                ['conference_overview', 'conference', 'Our Meeting Spaces', NULL, 'Professional Conference Facilities', 'State-of-the-art venues for your business meetings and events', 1],
                ['events_overview', 'events', 'Upcoming Events', NULL, 'Special Events & Occasions', 'Join us for memorable celebrations and special gatherings', 1],
                // Upcoming Events (homepage section)
                ['upcoming_events', 'index', "What's Happening", NULL, 'Upcoming Events', "Don't miss out on our carefully curated experiences and celebrations", 6]
            ];

            // Find the matching default
            $default = null;
            foreach ($all_defaults as $d) {
                if ($d[0] === $section_key && $d[1] === $page) {
                    $default = $d;
                    break;
                }
            }

            if (!$default) {
                throw new Exception('No default found for this section.');
            }

            // Update the section to default values
            $stmt = $pdo->prepare("
                UPDATE section_headers
                SET section_label = ?,
                    section_subtitle = ?,
                    section_title = ?,
                    section_description = ?,
                    display_order = ?,
                    is_active = 1,
                    updated_at = NOW()
                WHERE section_key = ? AND page = ?
            ");

            $stmt->execute([
                $default[2], // section_label
                $default[3], // section_subtitle
                $default[4], // section_title
                $default[5], // section_description
                $default[6], // display_order
                $section_key,
                $page
            ]);

            // Clear cache
            require_once __DIR__ . '/../config/cache.php';
            clearCache();

            $message = 'Section header reset to default successfully!';
            $success = true;
        }
    } catch (Exception $e) {
        $error = 'Error: ' . $e->getMessage();
    }

    // PRG — preserve flash message through redirect to avoid form re-submit on refresh
    $tab = in_array($action, ['update_hero', 'create_hero'], true) ? '#tab-heroes' : '#tab-sections';
    $flash_key = $success ? 'flash_success' : 'flash_error';
    $_SESSION[$flash_key] = $success ? $message : $error;
    header('Location: section-headers-management.php' . $tab);
    exit;
}

// Recover flash message from session
if (!empty($_SESSION['flash_success'])) {
    $message = $_SESSION['flash_success'];
    $success = true;
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Get filter parameters
$filter_page = $_GET['page_filter'] ?? 'all';

// Fetch all section headers
try {
    if ($filter_page === 'all') {
        $stmt = $pdo->query("
            SELECT * FROM section_headers
            ORDER BY page, display_order, section_title
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM section_headers
            WHERE page = ?
            ORDER BY display_order, section_title
        ");
        $stmt->execute([$filter_page]);
    }
    $section_headers = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get unique pages for filter
    $pages_stmt = $pdo->query("SELECT DISTINCT page FROM section_headers ORDER BY page");
    $pages = $pages_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $error = 'Error loading section headers: ' . $e->getMessage();
    $section_headers = [];
    $pages = [];
}

// Fetch all page heroes for the hero text tab
try {
    $page_heroes_rows = $pdo->query("
        SELECT id, page_slug, page_url, hero_title, hero_subtitle,
               hero_description, primary_cta_text, primary_cta_link,
               is_active
        FROM page_heroes
        ORDER BY page_slug ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $page_heroes_rows = [];
}

$current_page = 'section-headers-management.php';
$page_title = 'Section Headers Management';
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
    <title><?php echo htmlspecialchars($page_title); ?> - Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/section-headers.css?v=<?php echo @filemtime(__DIR__ . '/css/section-headers.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="page-header">
            <h2 class="page-title">
                <i class="fas fa-heading"></i> Section Headers Management
            </h2>
            <p class="text-muted">Manage live section headers and page hero text used across the frontend.</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-<?php echo $success ? 'success' : 'info'; ?>">
                <i class="fas fa-<?php echo $success ? 'check-circle' : 'info-circle'; ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <!-- Tab Navigation -->
        <div class="sh-tabs" style="display:flex;gap:0;border-bottom:2px solid var(--gold);margin-bottom:24px;">
            <button type="button" class="sh-tab sh-tab--active" data-tab="heroes"
                style="padding:12px 28px;font-size:14px;font-weight:700;border:none;background:var(--gold);color:var(--admin-text,#1f2a37);cursor:pointer;border-radius:6px 6px 0 0;letter-spacing:.04em;">
                <i class="fas fa-image"></i> Page Hero Text
            </button>
            <button type="button" class="sh-tab" data-tab="sections"
                style="padding:12px 28px;font-size:14px;font-weight:700;border:none;background:#f0ede8;color:var(--navy);cursor:pointer;border-radius:6px 6px 0 0;letter-spacing:.04em;margin-left:4px;">
                <i class="fas fa-heading"></i> Section Headers
            </button>
        </div>

        <!-- ===== TAB: PAGE HEROES ===== -->
        <div id="tab-heroes" class="sh-tab-panel">
            <p class="text-muted" style="margin-bottom:16px;">Edit the hero banner text (title, subtitle, description, buttons) shown at the top of each page. Images and videos are managed in <a href="media-management.php" style="color:var(--gold);">Media Portal</a>.</p>

            <!-- Quick-jump page filter for heroes -->
            <?php $heroFilter = $_GET['hero_filter'] ?? ''; ?>
            <div class="filter-bar" style="margin-bottom:20px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <label style="font-weight:600;color:var(--navy);">Jump to page:</label>
                    <select onchange="window.location.href='section-headers-management.php?hero_filter=' + this.value + '#tab-heroes'"
                        style="padding:8px 14px;border:2px solid #ddd;border-radius:6px;font-size:14px;">
                        <option value="">All Pages (<?= count($page_heroes_rows) ?>)</option>
                        <?php foreach ($page_heroes_rows as $_ph): ?>
                            <option value="<?= htmlspecialchars($_ph['page_slug']) ?>"
                                <?= $heroFilter === $_ph['page_slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($_ph['page_slug']) ?><?= !$_ph['is_active'] ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($heroFilter !== ''): ?>
                        <a href="section-headers-management.php#tab-heroes" style="color:var(--gold);font-size:13px;text-decoration:none;">
                            <i class="fas fa-times"></i> Show all
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add new hero entry -->
            <div class="card" style="margin-bottom:20px;border-left:4px solid var(--gold);">
                <button type="button" class="sh-tab" onclick="toggleEdit('new-hero-form')"
                    style="background:var(--gold);color:white;border:none;padding:9px 18px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;margin-bottom:0;">
                    <i class="fas fa-plus"></i> Add Hero for New Page
                </button>
                <form method="POST" id="edit_new-hero-form" style="display:none;margin-top:16px;">
                    <input type="hidden" name="action" value="create_hero">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                Page Slug <span style="color:#dc3545;">*</span>
                                <span style="color:#999;font-weight:400;font-size:12px;"> — e.g. <code>booking</code>, <code>privacy-policy</code></span>
                            </label>
                            <input type="text" name="new_page_slug" required placeholder="booking"
                                pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                        <div>
                            <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                Page URL <span style="color:#999;font-weight:400;font-size:12px;"> — e.g. /booking.php</span>
                            </label>
                            <input type="text" name="new_page_url" placeholder="/booking.php"
                                style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                            Hero Title <span style="color:#dc3545;">*</span>
                        </label>
                        <input type="text" name="new_hero_title" required placeholder="e.g. Book Your Stay"
                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                    </div>
                    <div style="display:flex;gap:10px;">
                        <button type="submit" style="background:var(--gold);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                            <i class="fas fa-save"></i> Create Hero Entry
                        </button>
                        <button type="button" onclick="toggleEdit('new-hero-form')"
                            style="background:#6c757d;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;">
                            Cancel
                        </button>
                    </div>
                </form>
            </div>

            <div style="display:grid;gap:20px;">
                <?php foreach ($page_heroes_rows as $ph): ?>
                    <?php if ($heroFilter !== '' && $ph['page_slug'] !== $heroFilter) continue; ?>
                    <?php $hid = 'hero_' . (int)$ph['id']; ?>
                    <div style="background:white;border-radius:8px;padding:24px;box-shadow:0 2px 4px rgba(0,0,0,.1);border-left:4px solid <?php echo $ph['is_active'] ? 'var(--gold)' : '#ccc'; ?>;">
                        <div class="sh-hero-card-header">
                            <div>
                                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                                    <h3 style="margin:0;color:var(--navy);font-size:17px;"><?php echo htmlspecialchars($ph['page_slug']); ?></h3>
                                    <span style="padding:3px 10px;background:var(--navy);color:white;border-radius:10px;font-size:11px;font-weight:700;text-transform:uppercase;">HERO</span>
                                    <?php if (!$ph['is_active']): ?>
                                        <span style="padding:3px 10px;background:#dc3545;color:white;border-radius:10px;font-size:11px;font-weight:700;">INACTIVE</span>
                                    <?php endif; ?>
                                </div>
                                <p style="margin:0;color:#888;font-size:13px;"><?php echo htmlspecialchars($ph['page_url'] ?: ('/' . $ph['page_slug'] . '.php')); ?></p>
                            </div>
                        </div>

                        <!-- Edit Form (always visible — no toggle needed for hero pages) -->
                        <form method="POST" id="edit_<?php echo $hid; ?>"
                            style="padding:20px;background:#fff;border:2px solid var(--gold);border-radius:6px;">
                            <input type="hidden" name="action" value="update_hero">
                            <input type="hidden" name="hero_id" value="<?php echo (int)$ph['id']; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                            <div style="display:grid;gap:16px;">
                                <div>
                                    <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                        Hero Title <span style="color:#dc3545;">*</span>
                                        <span style="color:#999;font-weight:400;font-size:12px;"> — main H1 heading</span>
                                    </label>
                                    <input type="text" name="hero_title" required
                                        value="<?php echo htmlspecialchars($ph['hero_title']); ?>"
                                        style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                        Hero Subtitle
                                        <span style="color:#999;font-weight:400;font-size:12px;"> — italic line below title</span>
                                    </label>
                                    <input type="text" name="hero_subtitle"
                                        value="<?php echo htmlspecialchars($ph['hero_subtitle'] ?? ''); ?>"
                                        style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                </div>
                                <div>
                                    <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">
                                        Description
                                        <span style="color:#999;font-weight:400;font-size:12px;"> — optional paragraph below subtitle</span>
                                    </label>
                                    <textarea name="hero_description" rows="3"
                                        style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;resize:vertical;box-sizing:border-box;"><?php echo htmlspecialchars($ph['hero_description'] ?? ''); ?></textarea>
                                </div>
                                <div class="sh-cta-grid">
                                    <div>
                                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Primary Button Text</label>
                                        <input type="text" name="primary_cta_text"
                                            value="<?php echo htmlspecialchars($ph['primary_cta_text'] ?? ''); ?>"
                                            placeholder="e.g., Book Now"
                                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                    </div>
                                    <div>
                                        <label style="display:block;font-weight:700;margin-bottom:6px;color:var(--navy);">Primary Button Link</label>
                                        <input type="text" name="primary_cta_link"
                                            value="<?php echo htmlspecialchars($ph['primary_cta_link'] ?? ''); ?>"
                                            placeholder="e.g., booking.php"
                                            style="width:100%;padding:10px;border:2px solid #ddd;border-radius:6px;font-size:14px;box-sizing:border-box;">
                                    </div>
                                </div>
                                <div>
                                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                                        <input type="checkbox" name="hero_is_active" value="1"
                                            <?php echo $ph['is_active'] ? 'checked' : ''; ?>
                                            style="width:18px;height:18px;">
                                        <span style="font-weight:700;color:var(--navy);">Active (hero visible on page)</span>
                                    </label>
                                </div>
                            </div>

                            <div style="display:flex;gap:12px;margin-top:20px;padding-top:20px;border-top:1px solid #ddd;">
                                <button type="submit"
                                    style="background:var(--gold);color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;">
                                    <i class="fas fa-save"></i> Save Hero Text
                                </button>
                            </div>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div><!-- /tab-heroes -->

        <!-- ===== TAB: SECTION HEADERS ===== -->
        <div id="tab-sections" class="sh-tab-panel" style="display:none;">

            <!-- Page Filter -->
            <div class="filter-bar">
                <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                    <label style="font-weight: 600; color: var(--navy);">Filter by Page:</label>
                    <select id="pageFilter" onchange="window.location.href='section-headers-management.php?page_filter=' + this.value + '#tab-sections'"
                        style="padding: 8px 16px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                        <option value="all" <?php echo $filter_page === 'all' ? 'selected' : ''; ?>>All Pages</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?php echo htmlspecialchars($page); ?>"
                                <?php echo $filter_page === $page ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($page)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div style="margin-left: auto; color: #666; font-size: 14px;">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo count($section_headers); ?></strong> section(s) found
                    </div>
                </div>
            </div>

            <!-- Section Headers Grid -->
            <div class="headers-grid" style="display: grid; gap: 20px;">
                <?php if (empty($section_headers)): ?>
                    <div style="text-align: center; padding: 60px 20px; background: white; border-radius: 8px;">
                        <i class="fas fa-inbox" style="font-size: 48px; color: #ccc; margin-bottom: 16px;"></i>
                        <p style="color: #666; font-size: 16px;">No section headers found.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($section_headers as $header): ?>
                        <div class="header-card" style="background: white; border-radius: 8px; padding: 24px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid <?php echo $header['is_active'] ? 'var(--gold)' : '#ccc'; ?>;">
                            <div class="sh-card-header">
                                <div>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 8px;">
                                        <h3 style="margin: 0; color: var(--navy); font-size: 18px;">
                                            <?php echo htmlspecialchars($header['section_key']); ?>
                                        </h3>
                                        <span style="padding: 4px 12px; background: var(--navy); color: white; border-radius: 12px; font-size: 11px; text-transform: uppercase; font-weight: 600;">
                                            <?php echo htmlspecialchars($header['page']); ?>
                                        </span>
                                    </div>
                                    <div style="color: #666; font-size: 13px;">
                                        Display Order: <?php echo $header['display_order']; ?> |
                                        Status: <?php echo $header['is_active'] ? '<span style="color: #28a745;">Active</span>' : '<span style="color: #dc3545;">Inactive</span>'; ?>
                                    </div>
                                </div>

                                <div class="sh-card-actions">
                                    <button onclick="toggleEdit('<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>')"
                                        class="btn-small" style="background: var(--gold); color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                        <i class="fas fa-edit"></i> Edit
                                    </button>

                                    <form method="post" style="display: inline;" onsubmit="return confirm('Reset this section to factory default?')">
                                        <input type="hidden" name="action" value="reset_single">
                                        <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                        <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                        <button type="submit" class="btn-small" style="background: #6c757d; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                            <i class="fas fa-undo"></i> Reset
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Preview -->
                            <div class="sh-section-preview" style="padding: 20px; background: #f8f9fa; border-radius: 6px; margin-bottom: 20px;">
                                <div style="text-align: center;">
                                    <?php if (!empty($header['section_label'])): ?>
                                        <span style="display: inline-block; color: var(--gold); font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 2px; margin-bottom: 12px;">
                                            <?php echo htmlspecialchars($header['section_label']); ?>
                                        </span>
                                    <?php endif; ?>

                                    <?php if (!empty($header['section_subtitle'])): ?>
                                        <p style="font-family: 'Cormorant Garamond', Georgia, serif; font-style: italic; color: #7a7a7a; font-size: 18px; margin-bottom: 10px;">
                                            <?php echo htmlspecialchars($header['section_subtitle']); ?>
                                        </p>
                                    <?php endif; ?>

                                    <h2 style="font-family: 'Cormorant Garamond', Georgia, serif; font-size: 32px; font-weight: 700; color: var(--navy); margin-bottom: 16px;">
                                        <?php echo htmlspecialchars($header['section_title']); ?>
                                    </h2>

                                    <?php if (!empty($header['section_description'])): ?>
                                        <p style="color: #666; font-size: 16px; max-width: 600px; margin: 0 auto;">
                                            <?php echo htmlspecialchars($header['section_description']); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Edit Form (Hidden by default) -->
                            <form method="POST" id="edit_<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>"
                                style="display: none; padding: 20px; background: #fff; border: 2px solid var(--gold); border-radius: 6px;">
                                <input type="hidden" name="action" value="update_header">
                                <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

                                <div style="display: grid; gap: 16px;">
                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Label <span style="color: #999; font-weight: 400; font-size: 13px;">(Small uppercase tag)</span>
                                        </label>
                                        <input type="text" name="section_label"
                                            value="<?php echo htmlspecialchars($header['section_label'] ?? ''); ?>"
                                            placeholder="e.g., ACCOMMODATIONS"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Subtitle <span style="color: #999; font-weight: 400; font-size: 13px;">(Italic descriptive text)</span>
                                        </label>
                                        <input type="text" name="section_subtitle"
                                            value="<?php echo htmlspecialchars($header['section_subtitle'] ?? ''); ?>"
                                            placeholder="e.g., Where Comfort Meets Luxury"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Title <span style="color: #dc3545;">*</span>
                                        </label>
                                        <input type="text" name="section_title"
                                            value="<?php echo htmlspecialchars($header['section_title']); ?>"
                                            required
                                            placeholder="e.g., Luxurious Rooms & Suites"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px;">
                                    </div>

                                    <div>
                                        <label style="display: block; font-weight: 600; margin-bottom: 6px; color: var(--navy);">
                                            Section Description
                                        </label>
                                        <textarea name="section_description" rows="3"
                                            placeholder="e.g., Experience unmatched comfort in our meticulously designed rooms and suites"
                                            style="width: 100%; padding: 10px; border: 2px solid #ddd; border-radius: 6px; font-size: 14px; resize: vertical;"><?php echo htmlspecialchars($header['section_description'] ?? ''); ?></textarea>
                                    </div>

                                    <div>
                                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                                            <input type="checkbox" name="is_active" value="1"
                                                <?php echo $header['is_active'] ? 'checked' : ''; ?>
                                                style="width: 18px; height: 18px;">
                                            <span style="font-weight: 600; color: var(--navy);">Active (visible on website)</span>
                                        </label>
                                    </div>
                                </div>

                                <div style="display: flex; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                                    <button type="submit" class="btn-primary" style="background: var(--gold); color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600;">
                                        <i class="fas fa-save"></i> Save Changes
                                    </button>
                                    <button type="button" onclick="toggleEdit('<?php echo htmlspecialchars($header['section_key']); ?>_<?php echo htmlspecialchars($header['page']); ?>')"
                                        class="btn-secondary" style="background: #6c757d; color: white; border: none; padding: 10px 20px; border-radius: 6px; cursor: pointer; font-size: 14px;">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Danger zone: Revert all section headers -->
            <div class="card" style="margin-top:32px;border-left:4px solid #dc3545;background:#fff9f9;">
                <h3 style="margin:0 0 8px;color:#dc3545;font-size:16px;"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h3>
                <p style="margin:0 0 16px;color:#666;font-size:14px;">This will delete <strong>all custom section header edits</strong> and restore the factory-default text for every section. This cannot be undone.</p>
                <form method="post" onsubmit="return confirm('Reset ALL section headers to factory defaults?\n\nThis will permanently delete all custom changes.')">
                    <input type="hidden" name="action" value="revert_defaults">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <button type="submit" style="background:#dc3545;color:white;border:none;padding:10px 20px;border-radius:6px;cursor:pointer;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px;">
                        <i class="fas fa-undo-alt"></i> Revert All Section Headers to Defaults
                    </button>
                </form>
            </div>

        </div><!-- /tab-sections -->

        <!-- Style Guide -->
        <div class="card sh-style-guide">
            <h2 class="page-title sh-style-guide__heading"><i class="fas fa-info-circle"></i> Section Header Style Guide</h2>
            <div class="sh-style-guide__grid">
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Label</h4>
                    <p class="sh-style-guide__desc">
                        Small category tag above the title.<br>
                        <strong>Style:</strong> Gold, uppercase, bold, 14px<br>
                        <strong>Example:</strong> "ACCOMMODATIONS"
                    </p>
                </div>
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Subtitle</h4>
                    <p class="sh-style-guide__desc">
                        Elegant descriptive text between label and title.<br>
                        <strong>Style:</strong> Gray, italic, serif, 18px<br>
                        <strong>Example:</strong> "Where Comfort Meets Luxury"
                    </p>
                </div>
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Title</h4>
                    <p class="sh-style-guide__desc">
                        Main heading (H2) for the section.<br>
                        <strong>Style:</strong> Navy, bold, serif, 36px<br>
                        <strong>Example:</strong> "Luxurious Rooms &amp; Suites"
                    </p>
                </div>
                <div class="sh-style-guide__item">
                    <h4 class="sh-style-guide__term">Section Description</h4>
                    <p class="sh-style-guide__desc">
                        Supporting text below the title.<br>
                        <strong>Style:</strong> Gray, regular, 16px<br>
                        <strong>Example:</strong> "Experience unmatched comfort..."
                    </p>
                </div>
            </div>
        </div>

    </div><!-- /.content -->

    <script>
        function toggleEdit(id) {
            const form = document.getElementById('edit_' + id);
            if (!form) return;
            const nowVisible = form.style.display !== 'none' && form.style.display !== '';
            form.style.display = nowVisible ? 'none' : 'block';
            if (!nowVisible) {
                setTimeout(function() {
                    form.scrollIntoView({
                        behavior: 'smooth',
                        block: 'nearest'
                    });
                }, 50);
            }
        }

        // Tab switching
        document.querySelectorAll('.sh-tab').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = this.dataset.tab;
                document.querySelectorAll('.sh-tab').forEach(function(t) {
                    var isActive = t.dataset.tab === target;
                    t.style.background = isActive ? 'var(--gold)' : '#f0ede8';
                    t.style.color = isActive ? 'var(--admin-text,#1f2a37)' : 'var(--navy)';
                    t.classList.toggle('sh-tab--active', isActive);
                });
                document.querySelectorAll('.sh-tab-panel').forEach(function(p) {
                    p.style.display = p.id === 'tab-' + target ? 'block' : 'none';
                });
                // Preserve active tab in URL hash so page load can restore it
                history.replaceState(null, '', '#tab-' + target);
            });
        });

        // Restore tab from URL hash on load
        (function() {
            var hash = location.hash.replace('#', '');
            if (hash === 'tab-sections') {
                var btn = document.querySelector('[data-tab="sections"]');
                if (btn) btn.click();
            } else {
                // heroes is default — clean the hash out of the URL bar
                if (hash) history.replaceState(null, '', location.pathname + location.search);
            }
        }());
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

