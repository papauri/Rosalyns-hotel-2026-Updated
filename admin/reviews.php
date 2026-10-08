<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
require_once __DIR__ . '/../includes/reviews-display.php'; // rh_public_review_text(), rh_review_source_meta()
/** @var array $user */
/** @var string $csrf_token */

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');

// ---- Filters ---------------------------------------------------------------
$valid_statuses = ['all', 'pending', 'approved', 'rejected'];
$valid_ratings  = ['', '5', '4', '3', '2', '1', 'low'];
$valid_replies  = ['', 'needs', 'replied'];
$review_types   = [
    'general'    => 'General stay',
    'room'       => 'Room',
    'restaurant' => 'Restaurant & dining',
    'spa'        => 'Spa & wellness',
    'conference' => 'Conference & events',
    'gym'        => 'Fitness centre',
    'service'    => 'Staff & service',
];
$sort_options = [
    'newest'  => ['Newest first', 'r.created_at DESC, r.id DESC'],
    'oldest'  => ['Oldest first', 'r.created_at ASC, r.id ASC'],
    'highest' => ['Highest rated', 'r.rating DESC, r.created_at DESC'],
    'lowest'  => ['Lowest rated', 'r.rating ASC, r.created_at DESC'],
];

$source_options = ['guests' => 'Left on our website', 'imported' => 'Imported from the web (all)'];
foreach (rh_review_platforms() as $pk => $pdef) {
    $source_options[$pk] = '— ' . $pdef[0];
}
$source_options['other'] = '— Other websites';

$status_filter = (string)($_GET['status'] ?? 'all');
$rating_filter = (string)($_GET['rating'] ?? '');
$reply_filter  = (string)($_GET['reply'] ?? '');
$type_filter   = (string)($_GET['type'] ?? '');
$source_filter = (string)($_GET['source'] ?? '');
$sort          = (string)($_GET['sort'] ?? 'newest');
$search_query  = trim((string)($_GET['search'] ?? ''));

if (!in_array($status_filter, $valid_statuses, true)) { $status_filter = 'all'; }
if (!in_array($rating_filter, $valid_ratings, true))  { $rating_filter = ''; }
if (!in_array($reply_filter, $valid_replies, true))   { $reply_filter = ''; }
if (!isset($review_types[$type_filter]))              { $type_filter = ''; }
if ($source_filter !== '' && !isset($source_options[$source_filter])) { $source_filter = ''; }
if (!isset($sort_options[$sort]))                     { $sort = 'newest'; }
if (mb_strlen($search_query, 'UTF-8') > 120)          { $search_query = mb_substr($search_query, 0, 120, 'UTF-8'); }

// Every filter except status — the status tabs show counts within these.
$where  = [];
$params = [];
if ($search_query !== '') {
    $where[] = "(r.guest_name LIKE ? OR r.guest_email LIKE ? OR r.title LIKE ? OR r.comment LIKE ? OR rm.name LIKE ?)";
    $like = '%' . addcslashes($search_query, '%_\\') . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($rating_filter === 'low') {
    $where[] = "r.rating <= 2";
} elseif ($rating_filter !== '') {
    $where[] = "r.rating = ?";
    $params[] = (int)$rating_filter;
}
if ($reply_filter === 'needs') {
    $where[] = "NOT EXISTS (SELECT 1 FROM review_responses rr0 WHERE rr0.review_id = r.id)";
} elseif ($reply_filter === 'replied') {
    $where[] = "EXISTS (SELECT 1 FROM review_responses rr0 WHERE rr0.review_id = r.id)";
}
if ($type_filter !== '') {
    $where[] = "r.review_type = ?";
    $params[] = $type_filter;
}
if ($source_filter !== '') {
    [$srcSql, $srcParams] = rh_review_source_filter($source_filter);
    if ($srcSql !== '') {
        $where[] = $srcSql;
        array_push($params, ...$srcParams);
    }
}
$base_where = $where ? ' AND ' . implode(' AND ', $where) : '';

// Status tab counts (within the other filters)
$status_counts = ['all' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0];
$stmt = $pdo->prepare("
    SELECT r.status, COUNT(*) AS c
    FROM reviews r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    WHERE 1=1 {$base_where}
    GROUP BY r.status
");
$stmt->execute($params);
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (isset($status_counts[$row['status']])) {
        $status_counts[$row['status']] = (int)$row['c'];
    }
    $status_counts['all'] += (int)$row['c'];
}

$list_where  = $base_where;
$list_params = $params;
if ($status_filter !== 'all') {
    $list_where .= " AND r.status = ?";
    $list_params[] = $status_filter;
}

$per_page      = 10;
$total_reviews = $status_counts[$status_filter];
$total_pages   = max(1, (int)ceil($total_reviews / $per_page));
$page          = min(max(1, (int)($_GET['page'] ?? 1)), $total_pages);
$offset        = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT
        r.*,
        (SELECT COUNT(*) FROM review_responses rr WHERE rr.review_id = r.id) AS response_count,
        (SELECT rr.id FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC, rr.id DESC LIMIT 1) AS latest_response_id,
        (SELECT rr.response FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC, rr.id DESC LIMIT 1) AS latest_response,
        (SELECT rr.created_at FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC, rr.id DESC LIMIT 1) AS latest_response_date,
        (SELECT au.username FROM review_responses rr LEFT JOIN admin_users au ON au.id = rr.admin_id
          WHERE rr.review_id = r.id ORDER BY rr.created_at DESC, rr.id DESC LIMIT 1) AS latest_response_by,
        rm.name AS room_name
    FROM reviews r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    WHERE 1=1 {$list_where}
    ORDER BY {$sort_options[$sort][1]}
    LIMIT " . (int)$per_page . " OFFSET " . (int)$offset
);
$stmt->execute($list_params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Headline stats (whole table, unfiltered)
$stats = $pdo->query("
    SELECT
        SUM(status = 'pending')  AS pending,
        SUM(status = 'approved') AS approved,
        AVG(CASE WHEN status = 'approved' THEN rating END) AS avg_rating,
        SUM(status = 'approved' AND NOT EXISTS (SELECT 1 FROM review_responses rr WHERE rr.review_id = reviews.id)) AS awaiting_reply,
        SUM(status = 'approved' AND rating <= 2) AS low_rated
    FROM reviews
")->fetch(PDO::FETCH_ASSOC) ?: [];
$pending_count  = (int)($stats['pending'] ?? 0);
$approved_count = (int)($stats['approved'] ?? 0);
$avg_rating     = ($stats['avg_rating'] ?? null) !== null ? round((float)$stats['avg_rating'], 1) : null;
$awaiting_reply = (int)($stats['awaiting_reply'] ?? 0);
$low_rated      = (int)($stats['low_rated'] ?? 0);

$filters_active = $search_query !== '' || $rating_filter !== '' || $reply_filter !== '' || $type_filter !== '' || $source_filter !== '' || $sort !== 'newest';

/** Build a reviews.php URL that keeps the current filters, overriding some. */
$filter_url = static function (array $overrides = []) use ($status_filter, $rating_filter, $reply_filter, $type_filter, $source_filter, $sort, $search_query): string {
    $q = array_merge([
        'status' => $status_filter,
        'rating' => $rating_filter,
        'reply'  => $reply_filter,
        'type'   => $type_filter,
        'source' => $source_filter,
        'sort'   => $sort,
        'search' => $search_query,
    ], $overrides);
    $q = array_filter($q, static fn($v, $k) => $v !== '' && $v !== null && !($k === 'status' && $v === 'all') && !($k === 'sort' && $v === 'newest') && !($k === 'page' && (int)$v <= 1), ARRAY_FILTER_USE_BOTH);
    return 'reviews.php' . ($q ? '?' . http_build_query($q) : '');
};

$status_badge = ['pending' => 'badge-pending', 'approved' => 'badge-success', 'rejected' => 'badge-danger'];
$status_label = ['pending' => 'Pending', 'approved' => 'Published', 'rejected' => 'Rejected'];
$status_note  = [
    'pending'  => ['fa-eye-slash', 'Not on the website yet — waiting for moderation'],
    'approved' => ['fa-globe', 'Live on the website'],
    'rejected' => ['fa-ban', 'Hidden from the website'],
];

$scraper_location = trim(getSetting('address_line2', '') . ' ' . getSetting('address_country', 'Malawi'));
// Only whether a key is saved — the keys themselves never reach the browser.
$google_source_on = strpos((string)getSetting('reviews_google_places_key', ''), 'rk1:') === 0;
$brave_source_on  = strpos((string)getSetting('reviews_brave_search_key', ''), 'rk1:') === 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reviews Management | <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>"><!-- Admin Components (Alert, Modal) -->
    <link rel="stylesheet" href="css/reviews.css?v=<?php echo @filemtime(__DIR__ . '/css/reviews.css'); ?>">
    <script src="js/admin-components.js"></script>
</head>
<body>

    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <div class="reviews-header">
            <div>
                <h2 class="section-title">Reviews Management</h2>
                <p class="reviews-header__lede">Moderate guest reviews, reply publicly, and control what appears on the website. Only <strong>published</strong> reviews are shown to guests.</p>
            </div>
            <div class="reviews-header__actions">
                <button type="button" class="btn btn-primary" id="importer-toggle" aria-expanded="false" aria-controls="scraper-panel">
                    <i class="fas fa-globe-africa"></i> Import reviews from the web
                </button>
                <a href="../submit-review.php" target="_blank" rel="noopener" class="btn btn-light">
                    <i class="fas fa-external-link-alt"></i> Guest review form
                </a>
            </div>
        </div>

        <!-- Web review importer (opened from the header button) -->
        <section class="scraper-panel" id="scraper-panel" aria-labelledby="scraper-title" hidden>
            <div class="scraper-panel__top">
                <div class="scraper-panel__header">
                    <h3 id="scraper-title"><i class="fas fa-globe-africa"></i> Import reviews from the web</h3>
                    <p>Find reviews and mentions of the hotel on Google, Facebook, TikTok, Instagram, X, TripAdvisor and the wider web — each with the date it was posted. Imports land as <strong>Pending</strong> and are dated by when they were posted.</p>
                </div>
                <button type="button" class="scraper-panel__close" id="importer-close" aria-label="Close importer"><i class="fas fa-times"></i></button>
            </div>

            <div class="scraper-form">
                <div class="scraper-form__field">
                    <label for="scraper-hotel-name">Hotel name</label>
                    <input type="text" id="scraper-hotel-name" value="<?php echo htmlspecialchars($site_name); ?>" maxlength="150">
                </div>
                <div class="scraper-form__field">
                    <label for="scraper-location">Location</label>
                    <input type="text" id="scraper-location" value="<?php echo htmlspecialchars($scraper_location); ?>" maxlength="120">
                </div>
                <div class="scraper-form__field scraper-form__field--sm">
                    <label for="scraper-limit">Results</label>
                    <input type="number" id="scraper-limit" min="3" max="20" value="8">
                </div>
                <div class="scraper-form__field scraper-form__field--sm">
                    <label for="scraper-scope">Search in</label>
                    <select id="scraper-scope">
                        <option value="all" selected>All sources</option>
                        <option value="google">Google reviews only</option>
                        <option value="web">Web &amp; social only</option>
                    </select>
                </div>
                <div class="scraper-form__field scraper-form__field--sm">
                    <label for="scraper-sentiment">Looking for</label>
                    <select id="scraper-sentiment">
                        <option value="positive" selected>Praise</option>
                        <option value="negative">Complaints</option>
                    </select>
                </div>
                <button type="button" id="scraper-search-btn" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>

            <div class="scraper-sources-status" id="scraper-sources-status" aria-live="polite"></div>
            <div class="scraper-chips" id="scraper-chips" role="group" aria-label="Filter results by source" hidden></div>
            <div id="scraper-results" class="scraper-results" hidden></div>

            <p class="scraper-sources-note">
                <i class="fas fa-plug"></i>
                <span class="scraper-sources__chip <?php echo $google_source_on ? 'is-on' : ''; ?>">Google reviews: <?php echo $google_source_on ? 'connected' : 'not set up'; ?></span>
                <span class="scraper-sources__chip <?php echo $brave_source_on ? 'is-on' : ''; ?>">Brave search: <?php echo $brave_source_on ? 'connected' : 'not set up'; ?></span>
                <span class="scraper-sources__chip is-on">Free web search: always on</span>
                <a href="booking-settings.php#review-sources">Manage API keys in Hotel Settings &rarr;</a>
            </p>
        </section>

        <!-- Headline stats -->
        <div class="ck-kpis ck-kpis--4 reviews-stats">
            <a class="ck-kpi reviews-stat-link" href="<?php echo htmlspecialchars($filter_url(['status' => 'approved', 'rating' => '', 'reply' => '', 'type' => '', 'source' => '', 'search' => '', 'sort' => 'newest'])); ?>">
                <span class="ck-kpi__icon"><i class="fas fa-star"></i></span>
                <span class="ck-kpi__label">Average rating · <?php echo $approved_count; ?> published</span>
                <span class="ck-kpi__value"><?php echo $avg_rating !== null ? number_format($avg_rating, 1) . ' / 5' : '—'; ?></span>
            </a>
            <a class="ck-kpi reviews-stat-link ck-kpi--warn" href="<?php echo htmlspecialchars($filter_url(['status' => 'pending', 'rating' => '', 'reply' => '', 'type' => '', 'search' => '', 'sort' => 'oldest'])); ?>">
                <span class="ck-kpi__icon"><i class="fas fa-hourglass-half"></i></span>
                <span class="ck-kpi__label">Awaiting moderation</span>
                <span class="ck-kpi__value"><?php echo $pending_count; ?></span>
            </a>
            <a class="ck-kpi reviews-stat-link ck-kpi--info" href="<?php echo htmlspecialchars($filter_url(['status' => 'approved', 'reply' => 'needs', 'rating' => '', 'type' => '', 'search' => '', 'sort' => 'newest'])); ?>">
                <span class="ck-kpi__icon"><i class="fas fa-reply"></i></span>
                <span class="ck-kpi__label">Published, no reply yet</span>
                <span class="ck-kpi__value"><?php echo $awaiting_reply; ?></span>
            </a>
            <a class="ck-kpi reviews-stat-link ck-kpi--alert" href="<?php echo htmlspecialchars($filter_url(['status' => 'approved', 'rating' => 'low', 'reply' => '', 'type' => '', 'source' => '', 'search' => '', 'sort' => 'newest'])); ?>">
                <span class="ck-kpi__icon"><i class="fas fa-exclamation-triangle"></i></span>
                <span class="ck-kpi__label">Published 1–2★ reviews</span>
                <span class="ck-kpi__value"><?php echo $low_rated; ?></span>
            </a>
        </div>

        <!-- Filters -->
        <div class="reviews-toolbar">
            <nav class="filter-tabs reviews-tabs" aria-label="Filter by status">
                <?php foreach (['all' => 'All', 'pending' => 'Pending', 'approved' => 'Published', 'rejected' => 'Rejected'] as $tab_key => $tab_label): ?>
                    <a href="<?php echo htmlspecialchars($filter_url(['status' => $tab_key])); ?>"
                       class="filter-tab <?php echo $status_filter === $tab_key ? 'active' : ''; ?>"
                       <?php echo $status_filter === $tab_key ? 'aria-current="page"' : ''; ?>>
                        <?php echo $tab_label; ?> <span class="reviews-tabs__count"><?php echo $status_counts[$tab_key]; ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

            <form class="filters-bar" method="get" action="reviews.php" id="reviews-filter-form">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
                <div class="filter-group filter-group--grow">
                    <label for="search-input"><i class="fas fa-search"></i> Search</label>
                    <input type="search" id="search-input" name="search" maxlength="120"
                           placeholder="Guest, email, title, text or room…"
                           value="<?php echo htmlspecialchars($search_query); ?>">
                </div>
                <div class="filter-group">
                    <label for="rating-filter">Rating</label>
                    <select id="rating-filter" name="rating" data-autosubmit>
                        <option value="">Any rating</option>
                        <?php foreach (['5' => '5★', '4' => '4★', '3' => '3★', '2' => '2★', '1' => '1★', 'low' => '1–2★ (unhappy)'] as $rv => $rl): ?>
                            <option value="<?php echo $rv; ?>" <?php echo $rating_filter === (string)$rv ? 'selected' : ''; ?>><?php echo $rl; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="reply-filter">Reply</label>
                    <select id="reply-filter" name="reply" data-autosubmit>
                        <option value="">Any</option>
                        <option value="needs" <?php echo $reply_filter === 'needs' ? 'selected' : ''; ?>>Needs a reply</option>
                        <option value="replied" <?php echo $reply_filter === 'replied' ? 'selected' : ''; ?>>Replied</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="type-filter">About</label>
                    <select id="type-filter" name="type" data-autosubmit>
                        <option value="">Everything</option>
                        <?php foreach ($review_types as $tv => $tl): ?>
                            <option value="<?php echo $tv; ?>" <?php echo $type_filter === $tv ? 'selected' : ''; ?>><?php echo htmlspecialchars($tl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="source-filter">Source</label>
                    <select id="source-filter" name="source" data-autosubmit>
                        <option value="">All sources</option>
                        <?php foreach ($source_options as $sv => $sl): ?>
                            <option value="<?php echo htmlspecialchars($sv); ?>" <?php echo $source_filter === $sv ? 'selected' : ''; ?>><?php echo htmlspecialchars($sl); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="sort-filter">Sort</label>
                    <select id="sort-filter" name="sort" data-autosubmit>
                        <?php foreach ($sort_options as $sv => $so): ?>
                            <option value="<?php echo $sv; ?>" <?php echo $sort === $sv ? 'selected' : ''; ?>><?php echo $so[0]; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Search</button>
                    <?php if ($filters_active): ?>
                        <a href="<?php echo htmlspecialchars($filter_url(['rating' => '', 'reply' => '', 'type' => '', 'source' => '', 'sort' => 'newest', 'search' => ''])); ?>" class="btn btn-light"><i class="fas fa-times"></i> Clear</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <p class="reviews-result-count" role="status">
            <?php if ($total_reviews > 0): ?>
                Showing <?php echo $offset + 1; ?>–<?php echo min($offset + $per_page, $total_reviews); ?> of <?php echo $total_reviews; ?> review<?php echo $total_reviews === 1 ? '' : 's'; ?>
            <?php endif; ?>
        </p>

        <!-- Reviews List -->
        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No reviews found</h3>
                <p>
                    <?php if ($filters_active || $status_filter !== 'all'): ?>
                        Nothing matches these filters. <a href="reviews.php">Show all reviews</a>.
                    <?php else: ?>
                        No reviews have been submitted yet. Guests can leave one from the website's review form.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $review):
                $rid        = (int)$review['id'];
                $rstatus    = in_array($review['status'], ['pending', 'approved', 'rejected'], true) ? $review['status'] : 'pending';
                $rrating    = max(1, min(5, (int)$review['rating']));
                $guest_name = trim((string)$review['guest_name']) !== '' ? (string)$review['guest_name'] : 'Guest';
                $guest_mail = trim((string)($review['guest_email'] ?? ''));
                $has_email  = $guest_mail !== '' && filter_var($guest_mail, FILTER_VALIDATE_EMAIL);
                $source     = rh_review_source_meta($review['comment'] ?? '');
                $source_host = $source['url'] !== '' ? (string)parse_url($source['url'], PHP_URL_HOST) : '';
                $source_key  = $source['url'] !== '' ? rh_review_platform_key($source['url']) : '';
                $body_text  = rh_public_review_text($review['comment'] ?? '');
                $type_key   = (string)($review['review_type'] ?? 'general');
                $responses  = (int)$review['response_count'];
            ?>
                <article class="review-card <?php echo $rstatus; ?>" id="review-<?php echo $rid; ?>" data-review-id="<?php echo $rid; ?>">
                    <div class="review-header">
                        <div class="review-guest-info">
                            <div class="review-avatar" aria-hidden="true">
                                <?php echo htmlspecialchars(mb_strtoupper(mb_substr($guest_name, 0, 1, 'UTF-8'), 'UTF-8')); ?>
                            </div>
                            <div class="review-guest-details">
                                <h4><?php echo htmlspecialchars($guest_name); ?></h4>
                                <?php if ($has_email): ?>
                                    <p><a href="mailto:<?php echo htmlspecialchars($guest_mail); ?>"><?php echo htmlspecialchars($guest_mail); ?></a></p>
                                <?php else: ?>
                                    <p class="review-guest-details__muted">No email on file</p>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="review-meta">
                            <div class="review-rating" role="img" aria-label="<?php echo $rrating; ?> out of 5 stars">
                                <span class="stars">
                                    <?php echo str_repeat('<i class="fas fa-star"></i>', $rrating); ?><?php echo str_repeat('<i class="far fa-star"></i>', 5 - $rrating); ?>
                                </span>
                                <span class="rating-value"><?php echo $rrating; ?>/5</span>
                            </div>
                            <span class="badge <?php echo $status_badge[$rstatus]; ?>"><?php echo $status_label[$rstatus]; ?></span>
                            <span class="review-date" title="<?php echo htmlspecialchars((string)$review['created_at']); ?>">
                                <i class="far fa-clock"></i> <?php echo date('M j, Y', strtotime((string)$review['created_at'])); ?>
                            </span>
                        </div>
                    </div>

                    <div class="review-tags">
                        <span class="review-tag"><i class="fas fa-tag"></i> <?php echo htmlspecialchars($review_types[$type_key] ?? ucfirst($type_key)); ?></span>
                        <?php if (!empty($review['room_name'])): ?>
                            <span class="review-tag"><i class="fas fa-bed"></i> <?php echo htmlspecialchars($review['room_name']); ?></span>
                        <?php endif; ?>
                        <?php if ($source['url'] !== ''): ?>
                            <a class="review-tag review-tag--imported" href="<?php echo htmlspecialchars($source['url']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo htmlspecialchars($source['url']); ?>">
                                <i class="<?php echo htmlspecialchars($source_key !== '' && $source_key !== 'other' ? rh_review_platforms()[$source_key][1] : 'fas fa-globe-africa'); ?>"></i> Imported from <?php echo htmlspecialchars($source_key !== '' && $source_key !== 'other' ? rh_review_platform_label($source_key) : ($source_host !== '' ? $source_host : 'the web')); ?><?php echo $source['date'] !== '' ? ' · posted ' . htmlspecialchars(date('M j, Y', strtotime($source['date']))) : ''; ?>
                            </a>
                            <?php if ($source['date'] === ''): ?>
                                <button type="button" class="review-tag review-tag--action" data-action="fetch-date" title="Look up when this was originally posted and re-date the review">
                                    <i class="fas fa-calendar-plus"></i> Find post date
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <?php if (trim((string)$review['title']) !== ''): ?>
                        <h3 class="review-title"><?php echo htmlspecialchars($review['title']); ?></h3>
                    <?php endif; ?>

                    <div class="review-comment">
                        <?php echo nl2br(htmlspecialchars($body_text)); ?>
                    </div>

                    <?php
                    $cats = ['service_rating' => 'Service', 'cleanliness_rating' => 'Cleanliness', 'location_rating' => 'Location', 'value_rating' => 'Value'];
                    $cat_html = '';
                    foreach ($cats as $ck => $cl) {
                        if (!empty($review[$ck])) {
                            $cat_html .= '<div class="category-rating"><i class="fas fa-star"></i> ' . $cl . ': <span>' . (int)$review[$ck] . '/5</span></div>';
                        }
                    }
                    if ($cat_html !== '') {
                        echo '<div class="category-ratings">' . $cat_html . '</div>';
                    }
                    ?>

                    <?php if (!empty($review['latest_response'])): ?>
                        <div class="admin-response" id="response-<?php echo (int)$review['latest_response_id']; ?>">
                            <div class="admin-response-header">
                                <i class="fas fa-reply"></i>
                                <strong>Hotel reply</strong>
                                <span>· <?php echo date('M j, Y g:i A', strtotime((string)$review['latest_response_date'])); ?><?php echo !empty($review['latest_response_by']) ? ' · by ' . htmlspecialchars($review['latest_response_by']) : ''; ?><?php echo $responses > 1 ? ' · ' . ($responses - 1) . ' earlier repl' . ($responses - 1 === 1 ? 'y' : 'ies') : ''; ?></span>
                                <button type="button" class="admin-response-remove" data-action="remove-response" data-response-id="<?php echo (int)$review['latest_response_id']; ?>" title="Remove this reply">
                                    <i class="fas fa-trash-alt"></i><span class="sr-only">Remove reply</span>
                                </button>
                            </div>
                            <div class="admin-response-content">
                                <?php echo nl2br(htmlspecialchars($review['latest_response'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="review-visibility review-visibility--<?php echo $rstatus; ?>">
                        <i class="fas <?php echo $status_note[$rstatus][0]; ?>"></i> <?php echo $status_note[$rstatus][1]; ?>
                    </p>

                    <div class="review-actions">
                        <?php if ($rstatus !== 'approved'): ?>
                            <button type="button" data-action="status" data-status="approved" class="btn btn-success btn-sm">
                                <i class="fas fa-check"></i> <?php echo $rstatus === 'pending' ? 'Approve & publish' : 'Publish'; ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($rstatus !== 'rejected'): ?>
                            <button type="button" data-action="status" data-status="rejected" class="btn <?php echo $rstatus === 'approved' ? 'btn-warning' : 'btn-danger'; ?> btn-sm">
                                <i class="fas fa-eye-slash"></i> <?php echo $rstatus === 'approved' ? 'Unpublish' : 'Reject'; ?>
                            </button>
                        <?php endif; ?>
                        <?php if ($rstatus === 'rejected'): ?>
                            <button type="button" data-action="status" data-status="pending" class="btn btn-light btn-sm">
                                <i class="fas fa-undo"></i> Back to pending
                            </button>
                        <?php endif; ?>

                        <button type="button" data-action="toggle-reply" class="btn btn-info btn-sm" aria-expanded="false" aria-controls="response-form-<?php echo $rid; ?>">
                            <i class="fas fa-reply"></i> <?php echo $responses > 0 ? 'Reply again' : 'Reply'; ?>
                        </button>

                        <button type="button" data-action="delete" class="btn btn-dark btn-sm">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>

                    <!-- Reply form -->
                    <div class="response-form" id="response-form-<?php echo $rid; ?>" hidden>
                        <div class="response-form-header">
                            <label class="response-form-title" for="response-text-<?php echo $rid; ?>">
                                <i class="fas fa-reply"></i> Reply to <?php echo htmlspecialchars($guest_name); ?>
                            </label>
                            <span class="response-form-hint">
                                <i class="fas fa-info-circle"></i> Shown publicly under the review once it is published
                            </span>
                        </div>
                        <textarea id="response-text-<?php echo $rid; ?>" maxlength="5000"
                                  placeholder="Thank the guest, address any concerns specifically, and invite them back… (min. 10 characters)"></textarea>
                        <div class="response-form-footer">
                            <div class="char-count invalid" id="char-count-<?php echo $rid; ?>">
                                <span class="char-count-current">0</span> / 5000 characters (min. 10)
                            </div>
                            <label class="response-notify<?php echo $has_email ? '' : ' response-notify--disabled'; ?>">
                                <input type="checkbox" class="response-notify__input" <?php echo $has_email ? 'checked' : 'disabled'; ?>>
                                <?php echo $has_email ? 'Also email this reply to the guest' : 'No guest email — reply will only show on the website'; ?>
                            </label>
                        </div>
                        <div class="review-actions">
                            <button type="button" data-action="submit-reply" class="btn btn-primary btn-sm">
                                <i class="fas fa-paper-plane"></i> Post reply
                            </button>
                            <button type="button" data-action="toggle-reply" class="btn btn-light btn-sm">
                                Cancel
                            </button>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav class="pagination" aria-label="Reviews pages">
                    <?php if ($page > 1): ?>
                        <a href="<?php echo htmlspecialchars($filter_url(['page' => $page - 1])); ?>"><i class="fas fa-chevron-left"></i> Previous</a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active" aria-current="page"><?php echo $i; ?></span>
                        <?php elseif ($i === 1 || $i === $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <a href="<?php echo htmlspecialchars($filter_url(['page' => $i])); ?>"><?php echo $i; ?></a>
                        <?php elseif ($i === $page - 3 || $i === $page + 3): ?>
                            <span>…</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="<?php echo htmlspecialchars($filter_url(['page' => $page + 1])); ?>">Next <i class="fas fa-chevron-right"></i></a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>

    </div>

    <script>
    (function () {
        'use strict';

        const CSRF = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const FLASH_KEY = 'rh_reviews_flash';

        // ---- small helpers -------------------------------------------------
        function notify(msg, type) {
            if (window.Alert && typeof window.Alert.show === 'function') {
                window.Alert.show(msg, type || 'info');
            } else {
                console.log('[reviews]', msg);
            }
        }

        // Survives the reload that follows a status change, so the result isn't lost.
        function flashThenReload(msg, type) {
            try { sessionStorage.setItem(FLASH_KEY, JSON.stringify({ msg: msg, type: type || 'success' })); } catch (_) {}
            window.location.reload();
        }

        function confirmAction(opts) {
            if (window.AdminConfirm && typeof window.AdminConfirm.request === 'function') {
                return window.AdminConfirm.request(opts);
            }
            return Promise.resolve(window.confirm(opts.message || opts.title || 'Are you sure?'));
        }

        function setBusy(btn, busy) {
            if (!btn) return;
            if (typeof window.setButtonLoading === 'function') {
                window.setButtonLoading(btn, busy);
                return;
            }
            if (busy) {
                btn.dataset.originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Working…';
            } else {
                btn.disabled = false;
                if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
            }
        }

        // Parses JSON even on 4xx/5xx so the server's message reaches the admin.
        function api(url, options) {
            const opts = Object.assign({ credentials: 'same-origin' }, options || {});
            opts.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': CSRF }, opts.headers || {});
            return fetch(url, opts).then(function (res) {
                return res.json().catch(function () { return {}; }).then(function (data) {
                    if (!res.ok || !data || data.success === false) {
                        const msg = (data && (data.message || data.error)) || ('Request failed (HTTP ' + res.status + ')');
                        const detail = data && data.details && typeof data.details === 'object'
                            ? ' ' + Object.values(data.details).join(' ') : '';
                        throw new Error(msg + detail);
                    }
                    return data;
                });
            });
        }

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }

        // ---- flash from previous action -------------------------------------
        try {
            const raw = sessionStorage.getItem(FLASH_KEY);
            if (raw) {
                sessionStorage.removeItem(FLASH_KEY);
                const f = JSON.parse(raw);
                document.addEventListener('DOMContentLoaded', function () { notify(f.msg, f.type); });
                if (document.readyState !== 'loading') notify(f.msg, f.type);
            }
        } catch (_) {}

        // ---- filters: selects apply immediately ---------------------------------
        const filterForm = document.getElementById('reviews-filter-form');
        if (filterForm) {
            filterForm.querySelectorAll('[data-autosubmit]').forEach(function (sel) {
                sel.addEventListener('change', function () { filterForm.submit(); });
            });
        }

        // ---- per-review actions (event delegation) -------------------------------
        const STATUS_COPY = {
            approved: { title: 'Publish this review?', message: 'It will appear on the website straight away.', confirm: 'Publish', tone: 'success', icon: 'fa-check', done: 'Review published — it is now live on the website.' },
            rejected: { title: 'Hide this review?', message: 'It will be removed from the website. You can publish it again later.', confirm: 'Hide review', tone: 'warning', icon: 'fa-eye-slash', done: 'Review hidden from the website.' },
            pending:  { title: 'Move back to pending?', message: 'It stays hidden until someone approves it.', confirm: 'Move to pending', tone: 'default', icon: 'fa-undo', done: 'Review moved back to pending.' }
        };

        function updateCharCount(card) {
            const ta = card.querySelector('.response-form textarea');
            const counter = card.querySelector('.char-count');
            if (!ta || !counter) return;
            const len = ta.value.trim().length;
            counter.querySelector('.char-count-current').textContent = String(len);
            counter.classList.toggle('valid', len >= 10);
            counter.classList.toggle('invalid', len < 10);
        }

        function toggleReply(card) {
            const form = card.querySelector('.response-form');
            const opener = card.querySelector('.review-actions > [data-action="toggle-reply"]');
            if (!form) return;
            form.hidden = !form.hidden;
            if (opener) opener.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
            if (!form.hidden) {
                const ta = form.querySelector('textarea');
                if (ta) ta.focus();
            }
        }

        function submitReply(card, btn) {
            const id = card.dataset.reviewId;
            const ta = card.querySelector('.response-form textarea');
            const notifyBox = card.querySelector('.response-notify__input');
            const text = ta ? ta.value.trim() : '';
            if (text.length < 10) {
                notify('A reply needs at least 10 characters.', 'error');
                if (ta) ta.focus();
                return;
            }
            const fd = new FormData();
            fd.append('review_id', id);
            fd.append('response', text);
            fd.append('notify_guest', notifyBox && notifyBox.checked && !notifyBox.disabled ? '1' : '0');

            setBusy(btn, true);
            api('api/review-responses.php', { method: 'POST', body: fd })
                .then(function (data) {
                    let msg = 'Reply posted.';
                    let type = 'success';
                    if (data.email_status === 'sent') msg += ' The guest was emailed a copy.';
                    else if (data.email_status === 'failed') { msg += ' But the email to the guest failed: ' + (data.email_error || 'check email settings') + '.'; type = 'warning'; }
                    else if (data.email_status === 'no_guest_email') msg += ' No guest email on file, so nothing was sent.';
                    flashThenReload(msg, type);
                })
                .catch(function (err) { notify('Could not post the reply: ' + err.message, 'error'); setBusy(btn, false); });
        }

        function setStatus(card, status, btn) {
            const copy = STATUS_COPY[status];
            confirmAction({ title: copy.title, message: copy.message, confirmText: copy.confirm, tone: copy.tone, icon: copy.icon })
                .then(function (ok) {
                    if (!ok) return;
                    setBusy(btn, true);
                    return api('api/reviews.php', {
                        method: 'PUT',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ review_id: Number(card.dataset.reviewId), status: status })
                    })
                        .then(function () { flashThenReload(copy.done, 'success'); })
                        .catch(function (err) { notify('Could not update the review: ' + err.message, 'error'); setBusy(btn, false); });
                });
        }

        function deleteReview(card, btn) {
            confirmAction({ title: 'Delete this review permanently?', message: 'The review and all hotel replies to it will be erased. This cannot be undone — use "Unpublish" if you only want to hide it.', confirmText: 'Delete permanently', tone: 'danger', icon: 'fa-trash' })
                .then(function (ok) {
                    if (!ok) return;
                    setBusy(btn, true);
                    return api('api/reviews.php?review_id=' + encodeURIComponent(card.dataset.reviewId), { method: 'DELETE' })
                        .then(function () { flashThenReload('Review deleted.', 'success'); })
                        .catch(function (err) { notify('Could not delete the review: ' + err.message, 'error'); setBusy(btn, false); });
                });
        }

        function removeResponse(btn) {
            const rid = btn.dataset.responseId;
            confirmAction({ title: 'Remove this reply?', message: 'It will disappear from the website. Any email already sent to the guest cannot be recalled.', confirmText: 'Remove reply', tone: 'danger', icon: 'fa-trash-alt' })
                .then(function (ok) {
                    if (!ok) return;
                    btn.disabled = true;
                    return api('api/review-responses.php?response_id=' + encodeURIComponent(rid), { method: 'DELETE' })
                        .then(function () { flashThenReload('Reply removed.', 'success'); })
                        .catch(function (err) { notify('Could not remove the reply: ' + err.message, 'error'); btn.disabled = false; });
                });
        }

        document.addEventListener('click', function (e) {
            const btn = e.target.closest('[data-action]');
            if (!btn) return;
            const card = btn.closest('.review-card');
            switch (btn.dataset.action) {
                case 'status':          if (card) setStatus(card, btn.dataset.status, btn); break;
                case 'toggle-reply':    if (card) toggleReply(card); break;
                case 'submit-reply':    if (card) submitReply(card, btn); break;
                case 'delete':          if (card) deleteReview(card, btn); break;
                case 'remove-response': removeResponse(btn); break;
                case 'import':          importCandidate(Number(btn.dataset.index), btn); break;
                case 'fetch-date':      if (card) fetchPostDate(card, btn); break;
            }
        });

        document.addEventListener('input', function (e) {
            if (e.target.matches('.response-form textarea')) {
                updateCharCount(e.target.closest('.review-card'));
            }
        });

        // ---- web review importer -----------------------------------------------
        let scraperCandidates = [];
        let scraperSentiment = 'positive';

        const panel = document.getElementById('scraper-panel');
        const toggleBtn = document.getElementById('importer-toggle');
        function setImporterOpen(open) {
            if (!panel || !toggleBtn) return;
            panel.hidden = !open;
            toggleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open) {
                const first = document.getElementById('scraper-hotel-name');
                if (first) first.focus({ preventScroll: true });
                const reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                panel.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
            }
        }
        if (toggleBtn) toggleBtn.addEventListener('click', function () { setImporterOpen(panel.hidden); });
        const closeBtn = document.getElementById('importer-close');
        if (closeBtn) closeBtn.addEventListener('click', function () { setImporterOpen(false); toggleBtn.focus(); });
        if (/[?&]importer=open\b/.test(window.location.search)) setImporterOpen(true);

        const DATE_SOURCE_LABEL = {
            'Google': 'from Google',
            'source page': 'from the page itself',
            'snippet': 'from the search result',
            'search index': 'when a search engine indexed it — may be later than the post'
        };

        function fmtDate(iso) {
            if (!iso) return '';
            const d = new Date(iso + 'T12:00:00');
            return isNaN(d) ? iso : d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' });
        }

        function renderSources(sources, d) {
            const box = document.getElementById('scraper-sources-status');
            if (!box) return;
            const usage = d && d.google_calls != null && (sources || []).some(function (s) { return s.name === 'Google reviews' && s.status === 'ok'; })
                ? '<span class="scraper-source__detail">Google lookups this month: ' + d.google_calls + ' of about ' + d.google_free + ' free.</span>' : '';
            box.innerHTML = usage + (sources || []).map(function (s) {
                const cls = s.status === 'ok' ? 'is-ok' : (s.status === 'error' ? 'is-error' : (s.status === 'off' ? 'is-off' : 'is-limited'));
                const label = s.status === 'ok' ? (s.count + ' found') : (s.status === 'off' ? 'not set up' : (s.status === 'error' ? 'error' : '0 found'));
                return '<span class="scraper-source ' + cls + '" title="' + escapeHtml(s.detail || '') + '">' +
                    escapeHtml(s.name) + ': <strong>' + escapeHtml(label) + '</strong></span>' +
                    (s.detail && s.status !== 'off' ? '<span class="scraper-source__detail">' + escapeHtml(s.detail) + '</span>' : '');
            }).join('');
        }

        // Result filter chips: All / Google / Facebook / ...
        let activeChip = 'all';
        function renderChips(candidates) {
            const chips = document.getElementById('scraper-chips');
            if (!chips) return;
            const counts = {};
            const labels = {};
            candidates.forEach(function (c) {
                const k = c.platform_key || 'other';
                counts[k] = (counts[k] || 0) + 1;
                labels[k] = c.platform_label || 'Other website';
            });
            const keys = Object.keys(counts);
            activeChip = 'all';
            if (keys.length < 2) { chips.hidden = true; chips.innerHTML = ''; return; }
            chips.hidden = false;
            chips.innerHTML = '<button type="button" class="scraper-chip is-active" data-chip="all">All <span>' + candidates.length + '</span></button>' +
                keys.map(function (k) {
                    return '<button type="button" class="scraper-chip" data-chip="' + escapeHtml(k) + '">' + escapeHtml(labels[k]) + ' <span>' + counts[k] + '</span></button>';
                }).join('');
        }
        function applyChip(key) {
            activeChip = key;
            document.querySelectorAll('#scraper-chips .scraper-chip').forEach(function (b) { b.classList.toggle('is-active', b.dataset.chip === key); });
            let shown = 0;
            document.querySelectorAll('#scraper-results .scraper-card').forEach(function (card) {
                const show = key === 'all' || card.dataset.platform === key;
                card.hidden = !show;
                if (show) shown++;
            });
            const count = document.getElementById('scraper-shown-count');
            if (count) count.textContent = String(shown);
        }
        document.addEventListener('click', function (e) {
            const chip = e.target.closest('.scraper-chip');
            if (chip) applyChip(chip.dataset.chip);
        });

        const searchBtn = document.getElementById('scraper-search-btn');
        if (searchBtn) {
            searchBtn.addEventListener('click', function () {
                const hotelName = document.getElementById('scraper-hotel-name').value.trim();
                const location = document.getElementById('scraper-location').value.trim();
                const limit = parseInt(document.getElementById('scraper-limit').value || '8', 10);
                const sentiment = document.getElementById('scraper-sentiment').value === 'negative' ? 'negative' : 'positive';
                const scope = (document.getElementById('scraper-scope') || {}).value || 'all';
                if (!hotelName) {
                    notify('Enter the hotel name to search for.', 'error');
                    return;
                }
                const wrap = document.getElementById('scraper-results');
                wrap.hidden = false;
                wrap.innerHTML = '<div class="scraper-empty"><i class="fas fa-spinner fa-spin"></i> Searching Google, social media and the web and checking post dates… this usually takes 5–20 seconds.</div>';
                document.getElementById('scraper-sources-status').innerHTML = '';
                setBusy(searchBtn, true);
                api('api/review-scraper.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'search',
                        hotel_name: hotelName,
                        location: location,
                        limit: Math.min(20, Math.max(3, Number.isNaN(limit) ? 8 : limit)),
                        sentiment: sentiment,
                        sources: scope,
                        _csrf: CSRF
                    })
                })
                    .then(function (data) {
                        const d = data.data || {};
                        renderSources(d.sources || [], d);
                        renderCandidates(Array.isArray(d.candidates) ? d.candidates : [], d.sentiment === 'negative' ? 'negative' : 'positive', d.dated || 0);
                    })
                    .catch(function (err) {
                        wrap.innerHTML = '<div class="scraper-empty">Search failed: ' + escapeHtml(err.message) + '</div>';
                    })
                    .finally(function () { setBusy(searchBtn, false); });
            });
        }

        function renderCandidates(candidates, sentiment, dated) {
            const wrap = document.getElementById('scraper-results');
            scraperCandidates = candidates;
            scraperSentiment = sentiment;
            const label = sentiment === 'negative' ? 'complaints' : 'positive reviews or mentions';
            wrap.hidden = false;
            renderChips(candidates);
            if (!candidates.length) {
                wrap.innerHTML = '<div class="scraper-empty">' + (sentiment === 'negative'
                    ? 'No complaints about the hotel were found online.'
                    : 'Nothing new found. Check the source status above — the free web search is often throttled; connecting Google reviews gives dependable results.') + '</div>';
                return;
            }
            let html = '<div class="scraper-results__count"><span id="scraper-shown-count">' + candidates.length + '</span> of ' + candidates.length + ' ' + label + ' shown · ' + dated + ' with a post date · newest first.</div>';
            candidates.forEach(function (item, idx) {
                const src = /^https?:\/\//i.test(String(item.source_url || '')) ? String(item.source_url) : '';
                const already = item.already_imported;
                const indexDateOnly = item.date_source === 'search index';
                const goodDate = item.source_date && !indexDateOnly ? item.source_date : '';
                const rating = parseInt(item.rating, 10) || (sentiment === 'negative' ? 2 : 5);
                let ratingOptions = '';
                for (let r = 5; r >= 1; r--) {
                    ratingOptions += '<option value="' + r + '"' + (r === rating ? ' selected' : '') + '>' + r + '★</option>';
                }
                const platform = item.platform_key && item.platform_key !== 'other' ? (item.platform_label + (item.source_platform === 'Google reviews' ? ' reviews' : '')) : (item.source_platform || item.source_domain || 'Web');
                const dateLine = item.source_date
                    ? '<span class="scraper-card__date' + (indexDateOnly ? ' is-uncertain' : '') + '"><i class="far fa-calendar"></i> ' +
                        (indexDateOnly ? 'Seen ' : 'Posted ') + escapeHtml(fmtDate(item.source_date)) +
                        ' <small>(' + escapeHtml(DATE_SOURCE_LABEL[item.date_source] || 'date found') + ')</small></span>'
                    : '<span class="scraper-card__date is-missing"><i class="far fa-calendar-times"></i> Post date unknown — enter it below if you know it</span>';
                html +=
                    '<article class="scraper-card' + (already ? ' scraper-card--imported' : '') + '" data-index="' + idx + '" data-platform="' + escapeHtml(item.platform_key || 'other') + '">' +
                        '<div class="scraper-card__head">' +
                            '<span class="scraper-card__platform">' + escapeHtml(platform) + (item.rating ? ' · ' + '★'.repeat(rating) : '') + '</span>' +
                            dateLine +
                        '</div>' +
                        '<h4>' + escapeHtml(item.title || (sentiment === 'negative' ? 'Guest service concern' : 'Guest feedback')) + '</h4>' +
                        '<p class="scraper-card__snippet">' + escapeHtml(item.snippet || '') + '</p>' +
                        (src ? '<a class="scraper-card__source" href="' + escapeHtml(src) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(src.length > 90 ? src.slice(0, 90) + '…' : src) + '</a>' : '') +
                        (already
                            ? '<p class="scraper-card__already"><i class="fas fa-check-circle"></i> Already imported as review #' + already.id + ' (' + escapeHtml(already.status === 'approved' ? 'published' : already.status) + ').</p>'
                            : '<div class="scraper-card__inputs">' +
                                '<label>Name shown<input type="text" class="scraper-username" value="' + escapeHtml(item.username || '') + '" placeholder="Leave blank for “Guest”" maxlength="120"></label>' +
                                '<label>Rating<select class="scraper-rating">' + ratingOptions + '</select></label>' +
                              '</div>' +
                              '<div class="scraper-card__inputs">' +
                                '<label>Posted on<input type="date" class="scraper-source-date" max="' + new Date().toISOString().slice(0, 10) + '" value="' + escapeHtml(goodDate) + '"></label>' +
                                '<label>Email (optional)<input type="email" class="scraper-email" value="' + escapeHtml(item.email || '') + '" placeholder="user@example.com" maxlength="190"></label>' +
                              '</div>' +
                              '<div class="scraper-card__actions">' +
                                '<button type="button" class="btn btn-primary btn-sm" data-action="import" data-index="' + idx + '"><i class="fas fa-file-import"></i> Import as pending</button>' +
                              '</div>') +
                    '</article>';
            });
            wrap.innerHTML = html;
        }

        function importCandidate(index, btn) {
            const candidate = scraperCandidates[index];
            const card = document.querySelector('.scraper-card[data-index="' + index + '"]');
            if (!candidate || !card) {
                notify('That result is no longer available — please search again.', 'error');
                return;
            }
            const email = card.querySelector('.scraper-email').value.trim();
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                notify('That email address doesn’t look valid. Fix it or leave it blank.', 'error');
                return;
            }
            const postedOn = card.querySelector('.scraper-source-date').value.trim();
            setBusy(btn, true);
            api('api/review-scraper.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'import',
                    rating: parseInt(card.querySelector('.scraper-rating').value, 10) || (scraperSentiment === 'negative' ? 2 : 5),
                    username: card.querySelector('.scraper-username').value.trim(),
                    email: email,
                    source_date: postedOn,
                    sentiment: scraperSentiment,
                    candidate: candidate,
                    _csrf: CSRF
                })
            })
                .then(function (data) {
                    const d = data.data || {};
                    card.classList.add('scraper-card--imported');
                    btn.innerHTML = '<i class="fas fa-check"></i> Imported — in Pending';
                    btn.disabled = true;
                    notify('Imported as pending review #' + (d.review_id || '') + (d.source_date ? ', dated ' + fmtDate(d.source_date) : ', dated today (no post date)') + '. Publish it from the Pending tab once checked.', 'success');
                })
                .catch(function (err) {
                    setBusy(btn, false);
                    notify('Import failed: ' + err.message, 'error');
                });
        }

        function fetchPostDate(card, btn) {
            const reviewId = Number(card.dataset.reviewId);
            setBusy(btn, true);
            api('api/review-scraper.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'fetch_date', review_id: reviewId, _csrf: CSRF })
            })
                .then(function (data) {
                    flashThenReload('Post date found: ' + fmtDate((data.data || {}).source_date) + '. The review is now dated accordingly.', 'success');
                })
                .catch(function (err) {
                    setBusy(btn, false);
                    // Couldn't read it automatically — let the admin enter it from the post.
                    const ask = window.AdminConfirm && window.AdminConfirm.prompt
                        ? window.AdminConfirm.prompt({ title: 'Enter the post date', message: err.message + ' Open the source link, check when it was posted, and enter that date.', inputLabel: 'Posted on (YYYY-MM-DD)', inputPlaceholder: 'e.g. 2024-08-05', confirmText: 'Save date', icon: 'fa-calendar-plus' })
                        : Promise.resolve(window.prompt(err.message + '\nEnter the post date (YYYY-MM-DD):', ''));
                    ask.then(function (value) {
                        const v = String(value || '').trim();
                        if (!v) return;
                        if (!/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                            notify('Use the format YYYY-MM-DD, e.g. 2024-08-05.', 'error');
                            return;
                        }
                        setBusy(btn, true);
                        api('api/review-scraper.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'set_date', review_id: reviewId, date: v, _csrf: CSRF })
                        })
                            .then(function () { flashThenReload('Review dated ' + fmtDate(v) + '.', 'success'); })
                            .catch(function (e2) { notify(e2.message, 'error'); setBusy(btn, false); });
                    });
                });
        }

    })();
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>
