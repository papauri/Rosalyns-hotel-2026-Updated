<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

$user = [
    'id' => $_SESSION['admin_user_id'],
    'username' => $_SESSION['admin_username'],
    'role' => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name']
];
$site_name = getSetting('site_name');

// Get filter parameters
$status_filter = isset($_GET['status']) ? $_GET['status'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Validate status filter
$valid_statuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($status_filter, $valid_statuses)) {
    $status_filter = 'all';
}

// Build query
$sql = "
    SELECT
        r.*,
        (SELECT COUNT(*) FROM review_responses rr WHERE rr.review_id = r.id) as response_count,
        (SELECT response FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC LIMIT 1) as latest_response,
        (SELECT created_at FROM review_responses rr WHERE rr.review_id = r.id ORDER BY rr.created_at DESC LIMIT 1) as latest_response_date,
        rm.name as room_name
    FROM reviews r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    WHERE 1=1
";
$params = [];

if ($status_filter !== 'all') {
    $sql .= " AND r.status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $sql .= " AND (r.guest_name LIKE ? OR r.guest_email LIKE ? OR r.title LIKE ? OR r.comment LIKE ?)";
    $search_param = "%{$search_query}%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$sql .= " ORDER BY r.created_at DESC";

// Get total count
$count_sql = "
    SELECT COUNT(*) as total
    FROM reviews r
    LEFT JOIN rooms rm ON r.room_id = rm.id
    WHERE 1=1
";
$count_params = [];

if ($status_filter !== 'all') {
    $count_sql .= " AND r.status = ?";
    $count_params[] = $status_filter;
}

if (!empty($search_query)) {
    $count_sql .= " AND (r.guest_name LIKE ? OR r.guest_email LIKE ? OR r.title LIKE ? OR r.comment LIKE ?)";
    $search_param = "%{$search_query}%";
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
    $count_params[] = $search_param;
}

$count_stmt = $pdo->prepare($count_sql);
$count_stmt->execute($count_params);
$count_row = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_reviews = (int)($count_row['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_reviews / $per_page));

// Get reviews for current page
$sql .= " LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$reviews = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get pending reviews count
$pending_stmt = $pdo->query("SELECT COUNT(*) as count FROM reviews WHERE status = 'pending'");
$pending_count = $pending_stmt->fetch(PDO::FETCH_ASSOC)['count'];
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
                <?php if ($pending_count > 0): ?>
                    <div class="pending-badge">
                        <i class="fas fa-exclamation-circle"></i>
                        <?php echo $pending_count; ?> Pending Review<?php echo $pending_count > 1 ? 's' : ''; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <section class="scraper-panel" aria-labelledby="scraper-title">
            <div class="scraper-panel__header">
                <h3 id="scraper-title"><i class="fas fa-globe-africa"></i> Web Feedback Importer</h3>
                <p>Search public web snippets and social platforms (TikTok, Facebook, Instagram, X/Twitter) for positive feedback or negative complaints, then import for moderation and service improvement.</p>
            </div>
            <div class="scraper-form">
                <div class="scraper-form__field">
                    <label for="scraper-hotel-name">Hotel Name</label>
                    <input type="text" id="scraper-hotel-name" value="<?php echo htmlspecialchars($site_name); ?>" maxlength="150">
                </div>
                <div class="scraper-form__field">
                    <label for="scraper-location">Location</label>
                    <input type="text" id="scraper-location" value="Mangochi Malawi" maxlength="120">
                </div>
                <div class="scraper-form__field scraper-form__field--sm">
                    <label for="scraper-limit">Results</label>
                    <input type="number" id="scraper-limit" min="3" max="20" value="8">
                </div>
                <div class="scraper-form__field scraper-form__field--sm">
                    <label for="scraper-sentiment">Feedback Type</label>
                    <select id="scraper-sentiment">
                        <option value="positive" selected>Positive</option>
                        <option value="negative">Negative</option>
                    </select>
                </div>
                <button type="button" id="scraper-search-btn" class="btn btn-primary" onclick="scrapeWebFeedback(this)">
                    <i class="fas fa-search"></i> Find Web Feedback
                </button>
            </div>
            <div id="scraper-results" class="scraper-results" hidden></div>
        </section>

        <!-- Filters Bar -->
        <div class="filters-bar">
            <div class="filter-group">
                <label for="status-filter"><i class="fas fa-filter"></i> Status:</label>
                <select id="status-filter" onchange="applyFilters()">
                    <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Reviews</option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="approved" <?php echo $status_filter === 'approved' ? 'selected' : ''; ?>>Approved</option>
                    <option value="rejected" <?php echo $status_filter === 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                </select>
            </div>

            <div class="filter-group">
                <label for="search-input"><i class="fas fa-search"></i> Search:</label>
                <input type="search" id="search-input" placeholder="Guest name, email, or comment..."
                       value="<?php echo htmlspecialchars($search_query); ?>"
                       onkeypress="if(event.key === 'Enter') applyFilters()">
            </div>

            <button onclick="applyFilters()" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>

            <button onclick="clearFilters()" class="btn btn-light">
                <i class="fas fa-times"></i> Clear
            </button>
        </div>

        <!-- Reviews List -->
        <?php if (empty($reviews)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <h3>No Reviews Found</h3>
                <p><?php echo !empty($search_query) ? 'Try adjusting your search or filters.' : 'No reviews have been submitted yet.'; ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <div class="review-card <?php echo $review['status']; ?>" id="review-<?php echo $review['id']; ?>">
                    <div class="review-header">
                        <div class="review-guest-info">
                            <div class="review-avatar">
                                <?php echo strtoupper(substr($review['guest_name'], 0, 1)); ?>
                            </div>
                            <div class="review-guest-details">
                                <h4><?php echo htmlspecialchars($review['guest_name']); ?></h4>
                                <p><?php echo htmlspecialchars($review['guest_email']); ?></p>
                            </div>
                        </div>
                        <div class="review-meta">
                            <div class="review-rating">
                                <span class="stars">
                                    <?php echo str_repeat('<i class="fas fa-star"></i>', $review['rating']); ?>
                                    <?php echo str_repeat('<i class="far fa-star"></i>', 5 - $review['rating']); ?>
                                </span>
                                <span class="rating-value"><?php echo $review['rating']; ?>/5</span>
                            </div>
                            <span class="badge badge-<?php echo $review['status']; ?>">
                                <?php echo ucfirst($review['status']); ?>
                            </span>
                            <span class="review-date">
                                <i class="far fa-clock"></i> <?php echo date('M d, Y', strtotime($review['created_at'])); ?>
                            </span>
                            <?php if ($review['room_name']): ?>
                                <span class="review-room">
                                    <i class="fas fa-bed"></i> <?php echo htmlspecialchars($review['room_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <h3 class="review-title"><?php echo htmlspecialchars($review['title']); ?></h3>

                    <div class="review-comment">
                        <?php echo nl2br(htmlspecialchars($review['comment'])); ?>
                    </div>

                    <?php if ($review['service_rating'] || $review['cleanliness_rating'] || $review['location_rating'] || $review['value_rating']): ?>
                        <div class="category-ratings">
                            <?php if ($review['service_rating']): ?>
                                <div class="category-rating">
                                    <i class="fas fa-star"></i> Service: <span><?php echo $review['service_rating']; ?>/5</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($review['cleanliness_rating']): ?>
                                <div class="category-rating">
                                    <i class="fas fa-star"></i> Cleanliness: <span><?php echo $review['cleanliness_rating']; ?>/5</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($review['location_rating']): ?>
                                <div class="category-rating">
                                    <i class="fas fa-star"></i> Location: <span><?php echo $review['location_rating']; ?>/5</span>
                                </div>
                            <?php endif; ?>
                            <?php if ($review['value_rating']): ?>
                                <div class="category-rating">
                                    <i class="fas fa-star"></i> Value: <span><?php echo $review['value_rating']; ?>/5</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($review['latest_response']): ?>
                        <div class="admin-response">
                            <div class="admin-response-header">
                                <i class="fas fa-reply"></i>
                                <strong>Admin Response</strong>
                                <span>• <?php echo date('M d, Y g:i A', strtotime($review['latest_response_date'])); ?></span>
                            </div>
                            <div class="admin-response-content">
                                <?php echo nl2br(htmlspecialchars($review['latest_response'])); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="review-actions">
                        <?php if ($review['status'] === 'pending'): ?>
                            <button type="button" onclick="updateReviewStatus(<?php echo $review['id']; ?>, 'approved', this)" class="btn btn-success btn-sm">
                                <i class="fas fa-check"></i> Approve
                            </button>
                            <button type="button" onclick="updateReviewStatus(<?php echo $review['id']; ?>, 'rejected', this)" class="btn btn-danger btn-sm">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php elseif ($review['status'] === 'approved'): ?>
                            <button type="button" onclick="updateReviewStatus(<?php echo $review['id']; ?>, 'rejected', this)" class="btn btn-warning btn-sm">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        <?php elseif ($review['status'] === 'rejected'): ?>
                            <button type="button" onclick="updateReviewStatus(<?php echo $review['id']; ?>, 'approved', this)" class="btn btn-success btn-sm">
                                <i class="fas fa-check"></i> Approve
                            </button>
                        <?php endif; ?>

                        <button type="button" onclick="toggleResponseForm(<?php echo $review['id']; ?>)" class="btn btn-info btn-sm">
                            <i class="fas fa-reply"></i> Respond
                        </button>

                        <button type="button" onclick="deleteReview(<?php echo $review['id']; ?>, this)" class="btn btn-dark btn-sm">
                            <i class="fas fa-trash"></i> Delete
                        </button>
                    </div>
                </div>

                <!-- Response Form (renders below the review card) -->
                <div class="response-form" id="response-form-<?php echo $review['id']; ?>" style="display: none;">
                    <div class="response-form-header">
                        <span class="response-form-title">
                            <i class="fas fa-reply"></i> Respond to Review
                        </span>
                        <span class="response-form-hint" title="Minimum 10 characters required">
                            <i class="fas fa-info-circle"></i> Min. 10 characters
                        </span>
                    </div>
                    <textarea id="response-text-<?php echo $review['id']; ?>"
                              placeholder="Write your response to this review... (minimum 10 characters)"
                              oninput="updateCharCount(<?php echo $review['id']; ?>)"></textarea>
                    <div class="char-count" id="char-count-<?php echo $review['id']; ?>">
                        <span class="char-count-current">0</span> characters
                    </div>
                    <div class="review-actions">
                        <button type="button" onclick="submitResponse(<?php echo $review['id']; ?>, this)" class="btn btn-primary btn-sm">
                            <i class="fas fa-paper-plane"></i> Submit Response
                        </button>
                        <button type="button" onclick="toggleResponseForm(<?php echo $review['id']; ?>)" class="btn btn-light btn-sm">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $page - 1; ?>">
                            <i class="fas fa-chevron-left"></i> Previous
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-chevron-left"></i> Previous</span>
                    <?php endif; ?>

                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php elseif ($i == 1 || $i == $total_pages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                            <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                        <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                            <span>...</span>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($page < $total_pages): ?>
                        <a href="?status=<?php echo $status_filter; ?>&search=<?php echo urlencode($search_query); ?>&page=<?php echo $page + 1; ?>">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled">Next <i class="fas fa-chevron-right"></i></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script>
        // Fallback loader/helpers in case admin-components.js is unavailable
        window.setButtonLoading = window.setButtonLoading || function(btn, loading) {
            if (!btn) return;
            if (loading) {
                if (!btn.dataset.originalHtml) btn.dataset.originalHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + (btn.textContent || 'Loading');
            } else {
                btn.disabled = false;
                if (btn.dataset.originalHtml) btn.innerHTML = btn.dataset.originalHtml;
            }
        };
        window.showLoadingOverlay = window.showLoadingOverlay || function(text){ console.debug('Loading:', text); };
        window.hideLoadingOverlay = window.hideLoadingOverlay || function(){ };
        window.Alert = window.Alert || { show: (msg, type) => { try { Modal.showMessage({ title: type === 'error' ? 'Error' : 'Notice', message: '<p>' + String(msg) + '</p>' }); } catch(_) {} } };
        const _pageCsrf = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        // Apply filters
        function applyFilters() {
            const status = document.getElementById('status-filter').value;
            const search = document.getElementById('search-input').value.trim();

            let url = '?status=' + encodeURIComponent(status);
            if (search) {
                url += '&search=' + encodeURIComponent(search);
            }

            window.location.href = url;
        }

        // Clear filters
        function clearFilters() {
            window.location.href = '?';
        }

        // Update character count
        function updateCharCount(reviewId) {
            const textarea = document.getElementById('response-text-' + reviewId);
            const charCountEl = document.getElementById('char-count-' + reviewId);
            const charCountCurrent = charCountEl.querySelector('.char-count-current');
            const length = textarea.value.trim().length;

            charCountCurrent.textContent = length;

            if (length >= 10) {
                charCountEl.classList.add('valid');
                charCountEl.classList.remove('invalid');
            } else {
                charCountEl.classList.add('invalid');
                charCountEl.classList.remove('valid');
            }
        }

        // Toggle response form
        function toggleResponseForm(reviewId) {
            const form = document.getElementById('response-form-' + reviewId);
            if (!form) {
                Alert.show('Error: Form not found', 'error');
                return;
            }
            form.style.display = form.style.display === 'none' ? 'block' : 'none';

            if (form.style.display === 'block') {
                document.getElementById('response-text-' + reviewId).focus();
            }
        }

        // Submit admin response
        function submitResponse(reviewId, btnEl) {
            const responseText = document.getElementById('response-text-' + reviewId).value.trim();

            if (!responseText) {
                Alert.show('Please enter a response', 'error');
                return;
            }

            if (responseText.length < 10) {
                Alert.show('Response must be at least 10 characters long', 'error');
                return;
            }

            const formData = new FormData();
            formData.append('review_id', reviewId);
            formData.append('response', responseText);

            setButtonLoading(btnEl, true);
            showLoadingOverlay('Submitting response...');

            fetch('api/review-responses.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': _pageCsrf }
            })
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    let message = 'Response added successfully';
                    if (data.email_sent) {
                        message += '. Email notification sent to guest.';
                    } else if (data.email_status === 'failed') {
                        message += '. Email could not be sent: ' + (data.email_error || 'Check email configuration');
                    } else if (data.email_status === 'no_guest_email') {
                        message += '. No guest email on file.';
                    }
                    Alert.show(message, data.email_sent ? 'success' : 'warning');
                    location.reload();
                } else {
                    Alert.show('Error: ' + (data.message || 'Failed to add response'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('An error occurred while adding response. Please try again.', 'error');
            })
            .finally(() => {
                hideLoadingOverlay();
                setButtonLoading(btnEl, false);
            });
        }

        // Update review status
        function updateReviewStatus(reviewId, newStatus, btnEl) {
            const statusText = newStatus === 'approved' ? 'approve' : 'reject';
            if (!confirm('Are you sure you want to ' + statusText + ' this review?')) {
                return;
            }

            const data = {
                review_id: reviewId,
                status: newStatus
            };

            setButtonLoading(btnEl, true);
            showLoadingOverlay((newStatus === 'approved' ? 'Approving' : 'Rejecting') + ' review...');

            fetch('api/reviews.php', {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': _pageCsrf
                },
                credentials: 'same-origin',
                body: JSON.stringify(data)
            })
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    Alert.show('Review ' + statusText + 'd successfully', 'success');
                    location.reload();
                } else {
                    Alert.show('Error: ' + (data.message || 'Failed to update review'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('An error occurred while updating review', 'error');
            })
            .finally(() => { hideLoadingOverlay(); setButtonLoading(btnEl, false); });
        }

        // Delete review
        function deleteReview(reviewId, btnEl) {
            if (!confirm('Are you sure you want to delete this review? This action cannot be undone.')) {
                return;
            }

            setButtonLoading(btnEl, true);
            showLoadingOverlay('Deleting review...');

            fetch('api/reviews.php?review_id=' + encodeURIComponent(reviewId), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': _pageCsrf }
            })
            .then(response => {
                if (!response.ok) throw new Error('HTTP ' + response.status);
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    Alert.show('Review deleted successfully', 'success');
                    const reviewCard = document.getElementById('review-' + reviewId);
                    reviewCard.style.opacity = '0';
                    reviewCard.style.transform = 'translateX(-100%)';
                    setTimeout(() => {
                        reviewCard.remove();
                        // Check if no reviews left
                        if (document.querySelectorAll('.review-card').length === 0) {
                            location.reload();
                        }
                    }, 300);
                } else {
                    Alert.show('Error: ' + (data.message || 'Failed to delete review'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Alert.show('An error occurred while deleting review', 'error');
            })
            .finally(() => { hideLoadingOverlay(); setButtonLoading(btnEl, false); });
        }

        function getScraperSentiment() {
            const raw = (document.getElementById('scraper-sentiment') || {}).value;
            return raw === 'negative' ? 'negative' : 'positive';
        }

        function getSentimentLabel(sentiment) {
            return sentiment === 'negative' ? 'negative feedback' : 'positive feedback';
        }

        function scrapeWebFeedback(btnEl) {
            const hotelName = document.getElementById('scraper-hotel-name').value.trim();
            const location = document.getElementById('scraper-location').value.trim();
            const limit = parseInt(document.getElementById('scraper-limit').value || '8', 10);
            const sentiment = getScraperSentiment();

            if (!hotelName) {
                Alert.show('Please enter a hotel name before searching.', 'error');
                return;
            }

            const payload = {
                action: 'search',
                hotel_name: hotelName,
                location: location,
                limit: Math.min(20, Math.max(3, Number.isNaN(limit) ? 8 : limit)),
                sentiment: sentiment,
                _csrf: _pageCsrf
            };

            setButtonLoading(btnEl, true);
            showLoadingOverlay('Searching public sources for ' + getSentimentLabel(sentiment) + '...');

            fetch('api/review-scraper.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || data.message || 'Search failed');
                }
                const responseSentiment = (data.data && data.data.sentiment) ? String(data.data.sentiment) : sentiment;
                renderScraperResults(data.data && data.data.candidates ? data.data.candidates : [], responseSentiment);
            })
            .catch(error => {
                Alert.show('Could not fetch feedback: ' + error.message, 'error');
            })
            .finally(() => {
                hideLoadingOverlay();
                setButtonLoading(btnEl, false);
            });
        }

        function renderScraperResults(candidates, sentiment) {
            const wrap = document.getElementById('scraper-results');
            const normalizedSentiment = sentiment === 'negative' ? 'negative' : 'positive';
            const isNegative = normalizedSentiment === 'negative';
            const ratingOptions = isNegative
                ? '<option value="1">1</option><option value="2" selected>2</option><option value="3">3</option>'
                : '<option value="5" selected>5</option><option value="4">4</option>';
            const fallbackTitle = isNegative ? 'Guest service concern' : 'Positive guest feedback';

            if (!candidates || candidates.length === 0) {
                wrap.hidden = false;
                wrap.innerHTML = '<div class="scraper-empty">No ' + getSentimentLabel(normalizedSentiment) + ' found for this search. Try a different location or hotel name.</div>';
                return;
            }

            let html = '<div class="scraper-results__count">Found ' + candidates.length + ' ' + getSentimentLabel(normalizedSentiment) + ' candidates.</div>';
            candidates.forEach((item, idx) => {
                const safeTitle = escapeHtml(item.title || fallbackTitle);
                const safeSnippet = escapeHtml(item.snippet || '');
                const safeSource = escapeHtml(item.source_url || '');
                const safeUser = escapeHtml(item.username || '');
                const safeEmail = escapeHtml(item.email || '');
                const safeSourceDate = escapeHtml(item.source_date || '');
                const sourceDomain = String(item.source_domain || '');
                const sourcePlatform = String(item.source_platform || '');
                const sourceDate = String(item.source_date || '');
                const sourceEmail = String(item.email || '');
                const metaParts = [];
                if (sourcePlatform) {
                    metaParts.push('Platform: ' + sourcePlatform);
                }
                if (sourceDomain) {
                    metaParts.push('Domain: ' + sourceDomain);
                }
                if (sourceDate) {
                    metaParts.push('Date: ' + sourceDate);
                }
                if (sourceEmail) {
                    metaParts.push('Email: ' + sourceEmail);
                }
                const safeMeta = escapeHtml(metaParts.join(' | '));
                html +=
                    '<article class="scraper-card" data-index="' + idx + '">' +
                        '<h4>' + safeTitle + '</h4>' +
                        '<p class="scraper-card__snippet">' + safeSnippet + '</p>' +
                        (safeMeta ? '<p class="scraper-card__snippet">' + safeMeta + '</p>' : '') +
                        '<a class="scraper-card__source" href="' + safeSource + '" target="_blank" rel="noopener">' + safeSource + '</a>' +
                        '<div class="scraper-card__inputs">' +
                            '<label>Username' +
                                '<input type="text" class="scraper-username" value="' + safeUser + '" placeholder="Source username (optional)" maxlength="120">' +
                            '</label>' +
                            '<label>Rating' +
                                '<select class="scraper-rating">' +
                                    ratingOptions +
                                '</select>' +
                            '</label>' +
                        '</div>' +
                        '<div class="scraper-card__inputs">' +
                            '<label>User Email (optional)' +
                                '<input type="email" class="scraper-email" value="' + safeEmail + '" placeholder="user@example.com" maxlength="190">' +
                            '</label>' +
                            '<label>Source Date' +
                                '<input type="date" class="scraper-source-date" value="' + safeSourceDate + '">' +
                            '</label>' +
                        '</div>' +
                        '<div class="scraper-card__actions">' +
                            '<button type="button" class="btn btn-secondary btn-sm" onclick="importScrapedFeedback(' + idx + ', this)"><i class="fas fa-hourglass-half"></i> Import for Review</button>' +
                        '</div>' +
                    '</article>';
            });

            wrap.dataset.candidates = JSON.stringify(candidates);
            wrap.dataset.sentiment = normalizedSentiment;
            wrap.hidden = false;
            wrap.innerHTML = html;
        }

        // Imports always land as 'pending' — the server ignores any status the client sends,
        // so there is no "Import & Approve" shortcut. Approve in the list below after
        // checking the text, rating and attribution.
        function importScrapedFeedback(index, btnEl) {
            const wrap = document.getElementById('scraper-results');
            let candidates = [];

            try {
                candidates = JSON.parse(wrap.dataset.candidates || '[]');
            } catch (_error) {
                Alert.show('Import data is invalid. Please search again.', 'error');
                return;
            }

            if (!candidates[index]) {
                Alert.show('Selected feedback was not found. Please search again.', 'error');
                return;
            }

            const card = wrap.querySelector('.scraper-card[data-index="' + index + '"]');
            if (!card) {
                Alert.show('Selected feedback card is missing.', 'error');
                return;
            }

            const usernameInput = card.querySelector('.scraper-username');
            const ratingSelect = card.querySelector('.scraper-rating');
            const emailInput = card.querySelector('.scraper-email');
            const sourceDateInput = card.querySelector('.scraper-source-date');
            const sentiment = (wrap.dataset.sentiment || '') === 'negative' ? 'negative' : 'positive';
            const ratingFallback = sentiment === 'negative' ? 2 : 5;
            const username = usernameInput ? usernameInput.value.trim() : '';
            const rating = ratingSelect ? parseInt(ratingSelect.value || String(ratingFallback), 10) : ratingFallback;
            const email = emailInput ? emailInput.value.trim() : '';
            const sourceDate = sourceDateInput ? sourceDateInput.value.trim() : '';

            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                Alert.show('Please enter a valid email format, or leave email blank.', 'error');
                return;
            }

            const payload = {
                action: 'import',
                rating: rating,
                username: username,
                email: email,
                source_date: sourceDate,
                sentiment: sentiment,
                candidate: candidates[index],
                _csrf: _pageCsrf
            };

            setButtonLoading(btnEl, true);
            showLoadingOverlay('Importing feedback...');

            fetch('api/review-scraper.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || data.message || 'Import failed');
                }
                Alert.show('Feedback imported successfully as ' + status + '.', 'success');
                card.classList.add('scraper-card--imported');
            })
            .catch(error => {
                Alert.show('Import failed: ' + error.message, 'error');
            })
            .finally(() => {
                hideLoadingOverlay();
                setButtonLoading(btnEl, false);
            });
        }

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/\"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        // Ensure functions are available on window for inline onclick handlers
        window.applyFilters = applyFilters;
        window.clearFilters = clearFilters;
        window.updateCharCount = updateCharCount;
        window.toggleResponseForm = toggleResponseForm;
        window.submitResponse = submitResponse;
        window.updateReviewStatus = updateReviewStatus;
        window.deleteReview = deleteReview;
        window.scrapeWebFeedback = scrapeWebFeedback;
        window.scrapePositiveFeedback = scrapeWebFeedback;
        window.importScrapedFeedback = importScrapedFeedback;
    </script>

    <?php require_once 'includes/admin-footer.php'; ?>

