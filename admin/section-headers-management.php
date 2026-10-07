<?php

/**
 * Section Headers Management
 * Admin interface for managing dynamic section headers
 */

require_once 'admin-init.php';
/** @var string $csrf_token */
require_once '../includes/alert.php';
require_once '../includes/section-headers.php';
require_once '../includes/form-validation.php';

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];

if (!hasPermission((int)$user['id'], 'section_headers') && !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    rhDenyAndRedirectHome((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? ''), basename($_SERVER['PHP_SELF']));
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
            $section_label = rh_clean_text($_POST['section_label'] ?? '');
            $section_subtitle = rh_clean_text($_POST['section_subtitle'] ?? '');
            $section_title = rh_clean_text($_POST['section_title'] ?? '');
            $section_description = trim((string)($_POST['section_description'] ?? ''));
            $is_active = isset($_POST['is_active']) ? 1 : 0;

            if (empty($section_key) || empty($page)) {
                throw new Exception('Section key and page are required.');
            }
            // The key + page identify an existing header; headers are not created here.
            $shExists = $pdo->prepare("SELECT COUNT(*) FROM section_headers WHERE section_key = ? AND page = ?");
            $shExists->execute([$section_key, $page]);
            if ((int)$shExists->fetchColumn() === 0) {
                throw new Exception('That section header no longer exists.');
            }
            if ($section_title === '') {
                throw new Exception('Section title is required.');
            }
            if (mb_strlen($section_label) > 100 || mb_strlen($section_subtitle) > 255 || mb_strlen($section_title) > 200) {
                throw new Exception('Too long: label 100, subtitle 255 and title 200 characters at most.');
            }
            if (mb_strlen($section_description) > 2000) {
                throw new Exception('Description cannot exceed 2000 characters.');
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
            $hero_title       = rh_clean_text($_POST['hero_title'] ?? '');
            $hero_subtitle    = rh_clean_text($_POST['hero_subtitle'] ?? '');
            $hero_description = trim((string)($_POST['hero_description'] ?? ''));
            $primary_cta_text = rh_clean_text($_POST['primary_cta_text'] ?? '');
            $primary_cta_link = rh_clean_text($_POST['primary_cta_link'] ?? '');
            $is_active        = isset($_POST['hero_is_active']) ? 1 : 0;

            if ($hero_id <= 0 || empty($hero_title)) {
                throw new Exception('Hero ID and title are required.');
            }
            $heroExists = $pdo->prepare("SELECT COUNT(*) FROM page_heroes WHERE id = ?");
            $heroExists->execute([$hero_id]);
            if ((int)$heroExists->fetchColumn() === 0) {
                throw new Exception('That page hero no longer exists.');
            }
            if (mb_strlen($hero_title) > 200 || mb_strlen($hero_subtitle) > 200 || mb_strlen($primary_cta_text) > 255 || mb_strlen($primary_cta_link) > 255) {
                throw new Exception('Too long: title 200, subtitle 200, button text 255 and button link 255 characters at most.');
            }
            if (mb_strlen($hero_description) > 2000) {
                throw new Exception('Hero description cannot exceed 2000 characters.');
            }
            if ($primary_cta_link !== '' && !preg_match('#^(https?://|/|\#|mailto:|tel:|[A-Za-z0-9][A-Za-z0-9._/?=&\#-]*$)#i', $primary_cta_link)) {
                throw new Exception('Button link must be a page (e.g. booking.php), an anchor (#book) or a full web address.');
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
            $page_url_new   = rh_clean_text($_POST['new_page_url'] ?? '');
            $hero_title_new = rh_clean_text($_POST['new_hero_title'] ?? '');

            if (empty($page_slug_new) || empty($hero_title_new)) {
                throw new Exception('Page slug and hero title are required.');
            }
            if (strlen($page_slug_new) > 100 || mb_strlen($hero_title_new) > 200 || mb_strlen($page_url_new) > 255) {
                throw new Exception('Too long: slug 100, title 200 and page address 255 characters at most.');
            }
            if ($page_url_new !== '' && !preg_match('#^(https?://|/)?[A-Za-z0-9][A-Za-z0-9._/?=&\#-]*$#', $page_url_new)) {
                throw new Exception('Page address must be a page such as /spa.php.');
            }

            // Check slug not already taken - checked and inserted under one lock
            rh_with_create_lock($pdo, 'page_hero', function () use ($pdo, $page_slug_new, $page_url_new, $hero_title_new) {
                $dup = rh_find_duplicate($pdo, 'page_heroes', 'page_slug', $page_slug_new);
                if ($dup) {
                    throw new Exception("A hero for slug '{$dup['value']}' already exists. Edit it from the list below.");
                }

                $ins = $pdo->prepare("
                    INSERT INTO page_heroes (page_slug, page_url, hero_title, hero_subtitle, hero_description, is_active, display_order)
                    VALUES (?, ?, ?, NULL, NULL, 1, 99)
                ");
                $ins->execute([$page_slug_new, $page_url_new ?: '/' . $page_slug_new . '.php', $hero_title_new]);
            });

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

    <div class="content sh-page">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Section Headers Management</h1>
                </div>
                <p class="rh-page-head__meta">Manage live section headers and page hero text used across the frontend.</p>
            </div>
        </header>

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
        <div class="sh-tabs">
            <button type="button" class="sh-tab sh-tab--active" data-tab="heroes">
                <i class="fas fa-image"></i> Page Hero Text
            </button>
            <button type="button" class="sh-tab" data-tab="sections">
                <i class="fas fa-heading"></i> Section Headers
            </button>
        </div>

        <!-- ===== TAB: PAGE HEROES ===== -->
        <div id="tab-heroes" class="sh-tab-panel">
            <?php $heroFilter = $_GET['hero_filter'] ?? ''; ?>
            <div class="sh-toolbar">
                <p class="sh-toolbar__note">Edit the hero banner text (title, subtitle, description, buttons) shown at the top of each page. Images and videos are managed in <a href="media-management.php">Media Portal</a>.</p>
                <div class="sh-toolbar__row">
                    <label for="heroJump">Jump to page:</label>
                    <select id="heroJump" class="sh-input" onchange="window.location.href='section-headers-management.php?hero_filter=' + this.value + '#tab-heroes'">
                        <option value="">All Pages (<?= count($page_heroes_rows) ?>)</option>
                        <?php foreach ($page_heroes_rows as $_ph): ?>
                            <option value="<?= htmlspecialchars($_ph['page_slug']) ?>"
                                <?= $heroFilter === $_ph['page_slug'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($_ph['page_slug']) ?><?= !$_ph['is_active'] ? ' (inactive)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($heroFilter !== ''): ?>
                        <a class="rh-mini-link" href="section-headers-management.php#tab-heroes">
                            <i class="fas fa-times"></i> Show all
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Add new hero entry -->
            <section class="rh-panel">
                <div class="rh-panel__head">
                    <h2 class="rh-panel__title">Add hero for new page</h2>
                    <div class="rh-panel__actions">
                        <button type="button" class="sh-btn sh-btn--primary" onclick="toggleEdit('new-hero-form')">
                            <i class="fas fa-plus"></i> Add Hero for New Page
                        </button>
                    </div>
                </div>
                <form method="POST" id="edit_new-hero-form" class="sh-form sh-edit-form">
                    <input type="hidden" name="action" value="create_hero">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                    <div class="sh-form__grid">
                        <div class="sh-field">
                            <label>
                                Page Slug <span class="sh-req">*</span>
                                <span class="sh-hint"> — e.g. <code>booking</code>, <code>privacy-policy</code></span>
                            </label>
                            <input type="text" name="new_page_slug" required placeholder="booking"
                                pattern="[a-z0-9\-]+" title="Lowercase letters, numbers and hyphens only" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>
                                Page URL <span class="sh-hint"> — e.g. /booking.php</span>
                            </label>
                            <input type="text" name="new_page_url" placeholder="/booking.php" class="sh-input">
                        </div>
                    </div>
                    <div class="sh-field">
                        <label>
                            Hero Title <span class="sh-req">*</span>
                        </label>
                        <input type="text" name="new_hero_title" required placeholder="e.g. Book Your Stay" class="sh-input">
                    </div>
                    <div class="sh-form__foot">
                        <button type="submit" class="sh-btn sh-btn--primary">
                            <i class="fas fa-save"></i> Create Hero Entry
                        </button>
                        <button type="button" class="sh-btn sh-btn--ghost" onclick="toggleEdit('new-hero-form')">
                            Cancel
                        </button>
                    </div>
                </form>
            </section>

            <?php foreach ($page_heroes_rows as $ph): ?>
                <?php if ($heroFilter !== '' && $ph['page_slug'] !== $heroFilter) continue; ?>
                <?php $hid = 'hero_' . (int)$ph['id']; ?>
                <section class="rh-panel">
                    <div class="rh-panel__head">
                        <h2 class="rh-panel__title"><?php echo htmlspecialchars($ph['page_slug']); ?></h2>
                        <div class="rh-panel__actions">
                            <span class="rh-pill rh-pill--muted">Hero</span>
                            <?php if (!$ph['is_active']): ?>
                                <span class="rh-pill rh-pill--alert">Inactive</span>
                            <?php endif; ?>
                            <span class="sh-meta"><?php echo htmlspecialchars($ph['page_url'] ?: ('/' . $ph['page_slug'] . '.php')); ?></span>
                        </div>
                    </div>

                    <!-- Edit Form (always visible — no toggle needed for hero pages) -->
                    <form method="POST" id="edit_<?php echo $hid; ?>" class="sh-form">
                        <input type="hidden" name="action" value="update_hero">
                        <input type="hidden" name="hero_id" value="<?php echo (int)$ph['id']; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

                        <div class="sh-field">
                            <label>
                                Hero Title <span class="sh-req">*</span>
                                <span class="sh-hint"> — main H1 heading</span>
                            </label>
                            <input type="text" name="hero_title" required
                                value="<?php echo htmlspecialchars($ph['hero_title']); ?>" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>
                                Hero Subtitle
                                <span class="sh-hint"> — italic line below title</span>
                            </label>
                            <input type="text" name="hero_subtitle"
                                value="<?php echo htmlspecialchars($ph['hero_subtitle'] ?? ''); ?>" class="sh-input">
                        </div>
                        <div class="sh-field">
                            <label>
                                Description
                                <span class="sh-hint"> — optional paragraph below subtitle</span>
                            </label>
                            <textarea name="hero_description" rows="3" class="sh-input"><?php echo htmlspecialchars($ph['hero_description'] ?? ''); ?></textarea>
                        </div>
                        <div class="sh-form__grid">
                            <div class="sh-field">
                                <label>Primary Button Text</label>
                                <input type="text" name="primary_cta_text"
                                    value="<?php echo htmlspecialchars($ph['primary_cta_text'] ?? ''); ?>"
                                    placeholder="e.g., Book Now" class="sh-input">
                            </div>
                            <div class="sh-field">
                                <label>Primary Button Link</label>
                                <input type="text" name="primary_cta_link"
                                    value="<?php echo htmlspecialchars($ph['primary_cta_link'] ?? ''); ?>"
                                    placeholder="e.g., booking.php" class="sh-input">
                            </div>
                        </div>
                        <label class="sh-check">
                            <input type="checkbox" name="hero_is_active" value="1"
                                <?php echo $ph['is_active'] ? 'checked' : ''; ?>>
                            <span>Active (hero visible on page)</span>
                        </label>

                        <div class="sh-form__foot">
                            <button type="submit" class="sh-btn sh-btn--primary">
                                <i class="fas fa-save"></i> Save Hero Text
                            </button>
                        </div>
                    </form>
                </section>
            <?php endforeach; ?>
        </div><!-- /tab-heroes -->

        <!-- ===== TAB: SECTION HEADERS ===== -->
        <div id="tab-sections" class="sh-tab-panel" style="display:none;">

            <!-- Page Filter -->
            <div class="sh-toolbar">
                <div class="sh-toolbar__row">
                    <label for="pageFilter">Filter by Page:</label>
                    <select id="pageFilter" class="sh-input" onchange="window.location.href='section-headers-management.php?page_filter=' + this.value + '#tab-sections'">
                        <option value="all" <?php echo $filter_page === 'all' ? 'selected' : ''; ?>>All Pages</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?php echo htmlspecialchars($page); ?>"
                                <?php echo $filter_page === $page ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($page)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="sh-meta sh-toolbar__count">
                        <i class="fas fa-info-circle"></i>
                        <strong><?php echo count($section_headers); ?></strong> section(s) found
                    </span>
                </div>
            </div>

            <?php if (empty($section_headers)): ?>
                <section class="rh-panel">
                    <p class="rh-empty">No section headers found.</p>
                </section>
            <?php else: ?>
                <?php foreach ($section_headers as $header): ?>
                    <?php $sh_edit_id = htmlspecialchars($header['section_key']) . '_' . htmlspecialchars($header['page']); ?>
                    <section class="rh-panel header-card">
                        <div class="rh-panel__head">
                            <h2 class="rh-panel__title">
                                <?php echo htmlspecialchars($header['section_key']); ?>
                                <span class="rh-pill rh-pill--muted"><?php echo htmlspecialchars($header['page']); ?></span>
                                <?php if ($header['is_active']): ?>
                                    <span class="rh-pill rh-pill--ok">Active</span>
                                <?php else: ?>
                                    <span class="rh-pill rh-pill--alert">Inactive</span>
                                <?php endif; ?>
                            </h2>
                            <div class="rh-panel__actions">
                                <span class="sh-meta">Order <?php echo $header['display_order']; ?></span>
                                <button type="button" onclick="toggleEdit('<?php echo $sh_edit_id; ?>')"
                                    class="sh-btn sh-btn--primary">
                                    <i class="fas fa-edit"></i> Edit
                                </button>

                                <form method="post" onsubmit="return confirm('Reset this section to factory default?')">
                                    <input type="hidden" name="action" value="reset_single">
                                    <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                                    <button type="submit" class="sh-btn sh-btn--ghost">
                                        <i class="fas fa-undo"></i> Reset
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Preview -->
                        <div class="sh-section-preview">
                            <?php if (!empty($header['section_label'])): ?>
                                <span class="sh-preview__label"><?php echo htmlspecialchars($header['section_label']); ?></span>
                            <?php endif; ?>

                            <?php if (!empty($header['section_subtitle'])): ?>
                                <p class="sh-preview__subtitle"><?php echo htmlspecialchars($header['section_subtitle']); ?></p>
                            <?php endif; ?>

                            <h3 class="sh-preview__title"><?php echo htmlspecialchars($header['section_title']); ?></h3>

                            <?php if (!empty($header['section_description'])): ?>
                                <p class="sh-preview__desc"><?php echo htmlspecialchars($header['section_description']); ?></p>
                            <?php endif; ?>
                        </div>

                        <!-- Edit Form (Hidden by default) -->
                        <form method="POST" id="edit_<?php echo $sh_edit_id; ?>" class="sh-form sh-edit-form">
                            <input type="hidden" name="action" value="update_header">
                            <input type="hidden" name="section_key" value="<?php echo htmlspecialchars($header['section_key']); ?>">
                            <input type="hidden" name="page" value="<?php echo htmlspecialchars($header['page']); ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">

                            <div class="sh-field">
                                <label>
                                    Section Label <span class="sh-hint">(Small uppercase tag)</span>
                                </label>
                                <input type="text" name="section_label"
                                    value="<?php echo htmlspecialchars($header['section_label'] ?? ''); ?>"
                                    placeholder="e.g., ACCOMMODATIONS" class="sh-input">
                            </div>

                            <div class="sh-field">
                                <label>
                                    Section Subtitle <span class="sh-hint">(Italic descriptive text)</span>
                                </label>
                                <input type="text" name="section_subtitle"
                                    value="<?php echo htmlspecialchars($header['section_subtitle'] ?? ''); ?>"
                                    placeholder="e.g., Where Comfort Meets Luxury" class="sh-input">
                            </div>

                            <div class="sh-field">
                                <label>
                                    Section Title <span class="sh-req">*</span>
                                </label>
                                <input type="text" name="section_title"
                                    value="<?php echo htmlspecialchars($header['section_title']); ?>"
                                    required
                                    placeholder="e.g., Luxurious Rooms & Suites" class="sh-input">
                            </div>

                            <div class="sh-field">
                                <label>Section Description</label>
                                <textarea name="section_description" rows="3"
                                    placeholder="e.g., Experience unmatched comfort in our meticulously designed rooms and suites"
                                    class="sh-input"><?php echo htmlspecialchars($header['section_description'] ?? ''); ?></textarea>
                            </div>

                            <label class="sh-check">
                                <input type="checkbox" name="is_active" value="1"
                                    <?php echo $header['is_active'] ? 'checked' : ''; ?>>
                                <span>Active (visible on website)</span>
                            </label>

                            <div class="sh-form__foot">
                                <button type="submit" class="sh-btn sh-btn--primary">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                                <button type="button" onclick="toggleEdit('<?php echo $sh_edit_id; ?>')"
                                    class="sh-btn sh-btn--ghost">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>

            <!-- Danger zone: Revert all section headers -->
            <section class="rh-panel sh-danger">
                <div class="rh-panel__head">
                    <h2 class="rh-panel__title"><i class="fas fa-exclamation-triangle"></i> Danger Zone</h2>
                </div>
                <div class="rh-panel__body">
                    <p class="sh-danger__text">This will delete <strong>all custom section header edits</strong> and restore the factory-default text for every section. This cannot be undone.</p>
                    <form method="post" onsubmit="return confirm('Reset ALL section headers to factory defaults?\n\nThis will permanently delete all custom changes.')">
                        <input type="hidden" name="action" value="revert_defaults">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES) ?>">
                        <button type="submit" class="sh-btn sh-btn--danger">
                            <i class="fas fa-undo-alt"></i> Revert All Section Headers to Defaults
                        </button>
                    </form>
                </div>
            </section>

        </div><!-- /tab-sections -->

        <!-- Style Guide -->
        <section class="rh-panel sh-style-guide">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Section header style guide</h2>
            </div>
            <table class="rh-kv"><tbody>
                <tr>
                    <th scope="row">Section Label</th>
                    <td>Small category tag above the title. Gold, uppercase, bold, 14px. Example: "ACCOMMODATIONS"</td>
                </tr>
                <tr>
                    <th scope="row">Section Subtitle</th>
                    <td>Elegant descriptive text between label and title. Gray, italic, serif, 18px. Example: "Where Comfort Meets Luxury"</td>
                </tr>
                <tr>
                    <th scope="row">Section Title</th>
                    <td>Main heading (H2) for the section. Navy, bold, serif, 36px. Example: "Luxurious Rooms &amp; Suites"</td>
                </tr>
                <tr>
                    <th scope="row">Section Description</th>
                    <td>Supporting text below the title. Gray, regular, 16px. Example: "Experience unmatched comfort..."</td>
                </tr>
            </tbody></table>
        </section>

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
                    t.classList.toggle('sh-tab--active', t.dataset.tab === target);
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

