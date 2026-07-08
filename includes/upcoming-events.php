<?php
/**
 * Upcoming Events Section – Reusable Include
 * 
 * A modern, sleek timeline-style display of upcoming events.
 * Can be included on any page. Checks per-page visibility settings.
 * 
 * Usage: 
 *   $upcoming_events_page = 'index'; // page identifier
 *   include 'includes/upcoming-events.php';
 * 
 * Requires: config/database.php (for $pdo and getSetting)
 */

// Ensure section-headers helper is available
if (!function_exists('renderSectionHeader')) {
    require_once __DIR__ . '/section-headers.php';
}

// Wrap everything in a try-catch to guarantee the page never breaks
try {

// Determine current page identifier
$upcoming_events_page = $upcoming_events_page ?? 'index';

// Check if the section is globally enabled
// Force homepage shell visibility even when settings are disabled/misaligned,
// so users always see this section block on index.php.
$ue_force_homepage_shell = ($upcoming_events_page === 'index');
$ue_enabled = getSetting('upcoming_events_enabled', '1');
if ($ue_enabled !== '1' && !$ue_force_homepage_shell) return;

// Check if this page is in the allowed pages list
$ue_pages_json = getSetting('upcoming_events_pages', '["index"]');
$ue_pages = json_decode($ue_pages_json, true) ?: ['index'];
if (!in_array($upcoming_events_page, $ue_pages) && !$ue_force_homepage_shell) return;

// How many events to show
$ue_max = (int) getSetting('upcoming_events_max_display', '4');
$ue_max = max(1, min($ue_max, 8)); // clamp 1-8

// Check if show_in_upcoming column exists (migration may not be run yet)
$ue_col_exists = false;
try {
    $ue_col_check = $pdo->query("SHOW COLUMNS FROM events LIKE 'show_in_upcoming'");
    $ue_col_exists = ($ue_col_check && $ue_col_check->rowCount() > 0);
} catch (\Throwable $e) {
    error_log("Upcoming events column check error: " . $e->getMessage());
}

// Fetch upcoming events marked for display.
// Backward-compatibility fallback: if no rows are explicitly flagged for
// upcoming display, use legacy behavior and show active future events.
$upcoming_events_list = [];
try {
    if ($ue_col_exists) {
        $ue_stmt = $pdo->prepare("
            SELECT id, title, description, event_date, start_time, end_time,
                   location, ticket_price, image_path, video_path
            FROM events
            WHERE is_active = 1
              AND show_in_upcoming = 1
              AND event_date >= CURDATE()
            ORDER BY event_date ASC, start_time ASC
            LIMIT :ue_limit
        ");
        $ue_stmt->bindValue(':ue_limit', $ue_max, PDO::PARAM_INT);
        $ue_stmt->execute();
        $upcoming_events_list = $ue_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($upcoming_events_list)) {
        $ue_legacy_stmt = $pdo->prepare("
            SELECT id, title, description, event_date, start_time, end_time,
                   location, ticket_price, image_path, video_path
            FROM events
            WHERE is_active = 1
              AND event_date >= CURDATE()
            ORDER BY event_date ASC, start_time ASC
            LIMIT :ue_limit
        ");
        $ue_legacy_stmt->bindValue(':ue_limit', $ue_max, PDO::PARAM_INT);
        $ue_legacy_stmt->execute();
        $upcoming_events_list = $ue_legacy_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (!empty($upcoming_events_list) && function_exists('applyManagedMediaOverrides')) {
        foreach ($upcoming_events_list as &$upcomingEventRow) {
            $upcomingEventRow = applyManagedMediaOverrides(
                $upcomingEventRow,
                'events',
                $upcomingEventRow['id'] ?? '',
                ['image_path']
            );
        }
        unset($upcomingEventRow);
    }
} catch (\Throwable $e) {
    error_log("Upcoming events fetch error: " . $e->getMessage());
    $upcoming_events_list = [];
}

$ue_currency = getSetting('currency_symbol');
?>

<!-- Upcoming Events Section -->
<section class="upcoming-events-section landing-section" id="upcoming-events" data-lazy-reveal>
    <div class="container">
        <?php renderSectionHeader('upcoming_events', 'index', [
            'label' => "What's Happening",
            'title' => 'Upcoming Events',
            'description' => "Don't miss out on our carefully curated experiences and celebrations"
        ], 'editorial-header section-header--editorial'); ?>

        <div class="ue-timeline-modern">
            <?php if (!empty($upcoming_events_list)): ?>
            <?php foreach ($upcoming_events_list as $ue_index => $ue_event): 
                $ue_date = new DateTime($ue_event['event_date']);
                $ue_day = $ue_date->format('d');
                $ue_month = $ue_date->format('M');
                $ue_year = $ue_date->format('Y');
                $ue_weekday = $ue_date->format('l');
                $ue_start = $ue_event['start_time'] ? date('g:i A', strtotime($ue_event['start_time'])) : '';
                $ue_end = $ue_event['end_time'] ? date('g:i A', strtotime($ue_event['end_time'])) : '';
                $ue_time_str = $ue_start;
                if ($ue_start && $ue_end) $ue_time_str = $ue_start . ' – ' . $ue_end;
                $ue_raw_desc = strip_tags($ue_event['description'] ?? '');
                $ue_desc = htmlspecialchars(strlen($ue_raw_desc) > 120 ? substr($ue_raw_desc, 0, 117) . '...' : $ue_raw_desc);
                // Fall back to video_path when it actually holds an image URL — legacy
                // data sometimes stored the event picture there instead of image_path.
                if (empty($ue_event['image_path']) && !empty($ue_event['video_path'])
                    && preg_match('/\.(jpe?g|png|webp|gif|avif)([?#].*)?$/i', $ue_event['video_path'])) {
                    $ue_event['image_path'] = $ue_event['video_path'];
                }
                $ue_has_image = !empty($ue_event['image_path']);
                $ue_price = floatval($ue_event['ticket_price']);
                // Use standard odd/even logic for timeline sides
                $ue_side = ($ue_index % 2 === 0) ? 'left' : 'right';
            ?>
            <div class="ue-timeline-item ue-timeline-item--<?php echo $ue_side; ?>">
                
                <!-- The Dot on the line -->
                <div class="ue-timeline-marker"></div>

                <!-- The Date (opposite side of content) -->
                <div class="ue-timeline-date">
                    <span class="ue-date-day"><?php echo $ue_day; ?></span>
                    <span class="ue-date-month"><?php echo $ue_month; ?></span>
                </div>

                <!-- The Content Card -->
                <div class="ue-timeline-content">
                    <div class="ue-card-modern">
                        <?php if ($ue_has_image): ?>
                        <div class="ue-card-image">
                            <img src="<?php echo htmlspecialchars($ue_event['image_path']); ?>" 
                                 alt="<?php echo htmlspecialchars($ue_event['title']); ?>"
                                 loading="lazy"
                                 decoding="async"
                                 width="640"
                                 height="420">
                            <?php if ($ue_price > 0): ?>
                            <div class="ue-price-tag"><?php echo $ue_currency . ' ' . number_format($ue_price, 0); ?></div>
                            <?php else: ?>
                            <div class="ue-price-tag ue-price-tag--free">Free</div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        
                        <div class="ue-card-details">
                            <h3 class="ue-card-title"><?php echo htmlspecialchars($ue_event['title']); ?></h3>
                            
                            <div class="ue-meta-row">
                                <span class="ue-meta"><i class="fas fa-clock"></i> <?php echo $ue_time_str ?: 'All Day'; ?></span>
                                <?php if (!empty($ue_event['location'])): ?>
                                <span class="ue-meta"><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ue_event['location']); ?></span>
                                <?php endif; ?>
                            </div>

                            <?php if ($ue_desc): ?>
                            <p class="ue-card-desc"><?php echo $ue_desc; ?></p>
                            <?php endif; ?>

                            <a href="events.php" class="ue-read-more">Details <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="ue-timeline-item ue-timeline-item--right">
                <div class="ue-timeline-marker"></div>
                <div class="ue-timeline-date">
                    <span class="ue-date-day">--</span>
                    <span class="ue-date-month">Soon</span>
                </div>
                <div class="ue-timeline-content">
                    <div class="ue-card-modern">
                        <div class="ue-card-details">
                            <h3 class="ue-card-title">New events are being scheduled</h3>
                            <div class="ue-meta-row">
                                <span class="ue-meta"><i class="fas fa-calendar-alt"></i> Upcoming updates</span>
                                <span class="ue-meta"><i class="fas fa-bell"></i> Check back shortly</span>
                            </div>
                            <p class="ue-card-desc">Our events calendar is being refreshed. Visit the full events page to see the latest updates as soon as they are published.</p>
                            <a href="events.php" class="ue-read-more">Go to Events <i class="fas fa-arrow-right"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="ue-footer">
            <a href="events.php" class="btn btn-outline">
                View Calendar <i class="fas fa-arrow-right"></i>
            </a>
        </div>
    </div>
</section>
<?php
} catch (\Throwable $e) {
    // Silently fail — never break the parent page
    error_log("Upcoming events section error: " . $e->getMessage());
}
?>
