<?php
// Include admin initialization (PHP-only, no HTML output)
require_once 'admin-init.php';

/** @var array $user */
$user = [
    'id'        => $_SESSION['admin_user_id'],
    'username'  => $_SESSION['admin_username'],
    'role'      => $_SESSION['admin_role'],
    'full_name' => $_SESSION['admin_full_name'],
];

$message = '';
$error   = '';

$hotel_reply_email = trim((string)getEmailSetting('email_from_email', ''));
if ($hotel_reply_email === '') {
    $hotel_reply_email = trim((string)getSetting('email_main', ''));
}
$hotel_reply_email_valid = filter_var($hotel_reply_email, FILTER_VALIDATE_EMAIL) !== false;

// Ensure the contact_inquiries table exists (it's also created by contact-us.php)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_inquiries (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
        reference_number VARCHAR(30) NOT NULL,
        name            VARCHAR(150) NOT NULL,
        email           VARCHAR(255) NOT NULL,
        phone           VARCHAR(50) DEFAULT NULL,
        subject         VARCHAR(255) NOT NULL,
        message         TEXT NOT NULL,
        consent         TINYINT(1) NOT NULL DEFAULT 0,
        status          ENUM('new','read','replied','archived') NOT NULL DEFAULT 'new',
        created_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_reference (reference_number),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (PDOException $e) {
    error_log('contact_inquiries table check: ' . $e->getMessage());
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['inquiry_action'])) {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $csrf_token)) {
        $error = 'Invalid request. Please try again.';
    } else {
        try {
            $inquiry_id = (int)($_POST['inquiry_id'] ?? 0);
            $action     = $_POST['inquiry_action'];

            if ($action === 'update_status' && $inquiry_id > 0) {
                $valid_statuses = ['new', 'read', 'replied', 'archived'];
                $new_status     = in_array($_POST['new_status'] ?? '', $valid_statuses) ? $_POST['new_status'] : 'read';
                $stmt = $pdo->prepare("UPDATE contact_inquiries SET status = ? WHERE id = ?");
                $stmt->execute([$new_status, $inquiry_id]);
                $message = 'Inquiry status updated to "' . htmlspecialchars($new_status) . '".';
                rh_log_event('contact_inquiries', 'info', 'Status updated', [
                    'inquiry_id' => $inquiry_id,
                    'new_status' => $new_status,
                    'by'         => $user['username'],
                ]);
            } elseif ($action === 'reply_email' && $inquiry_id > 0) {
                $reply_subject = trim((string)($_POST['reply_subject'] ?? ''));
                $reply_message = trim((string)($_POST['reply_message'] ?? ''));

                $fetch_stmt = $pdo->prepare("SELECT * FROM contact_inquiries WHERE id = ?");
                $fetch_stmt->execute([$inquiry_id]);
                $inquiry = $fetch_stmt->fetch(PDO::FETCH_ASSOC);

                if (!$inquiry) {
                    $error = 'Inquiry not found.';
                } elseif (!$hotel_reply_email_valid) {
                    $error = 'Hotel sender email is not configured. Set the Email From Address in email settings first.';
                } elseif (!filter_var($inquiry['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
                    $error = 'This inquiry does not have a valid guest email address.';
                } elseif ($reply_subject === '' || strlen($reply_subject) > 255) {
                    $error = 'Please provide a reply subject under 255 characters.';
                } elseif ($reply_message === '' || strlen($reply_message) > 5000) {
                    $error = 'Please provide a reply message under 5,000 characters.';
                } else {
                    require_once '../config/email.php';
                    $email_result = sendContactInquiryReplyEmail($inquiry, $reply_subject, $reply_message, $user);

                    if ($email_result['success']) {
                        $stmt = $pdo->prepare("UPDATE contact_inquiries SET status = 'replied', updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$inquiry_id]);
                        $message = 'Reply sent to ' . htmlspecialchars((string)$inquiry['email']) . ' from ' . htmlspecialchars($hotel_reply_email) . '.';
                        rh_log_event('contact_inquiries', 'info', 'Reply email sent', [
                            'inquiry_id' => $inquiry_id,
                            'reference'  => $inquiry['reference_number'] ?? null,
                            'to'         => $inquiry['email'] ?? null,
                            'from'       => $hotel_reply_email,
                            'by'         => $user['username'],
                        ]);
                    } else {
                        $error = 'Reply email could not be sent: ' . $email_result['message'];
                    }
                }
            } elseif ($action === 'delete' && $inquiry_id > 0) {
                $stmt = $pdo->prepare("DELETE FROM contact_inquiries WHERE id = ?");
                $stmt->execute([$inquiry_id]);
                $message = 'Inquiry deleted.';
            } elseif ($action === 'mark_all_read') {
                $pdo->exec("UPDATE contact_inquiries SET status = 'read' WHERE status = 'new'");
                $message = 'All new inquiries marked as read.';
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log('contact-inquiries admin PDO: ' . $e->getMessage());
        } catch (Throwable $e) {
            error_log('contact-inquiries admin error: ' . $e->getMessage());
            $error = 'An unexpected error occurred: ' . $e->getMessage();
        }
    }
}

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Filters
$search        = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$valid_filters = ['', 'new', 'read', 'replied', 'archived'];
if (!in_array($status_filter, $valid_filters)) {
    $status_filter = '';
}

// Fetch inquiries
$inquiries = [];
try {
    $sql    = "SELECT * FROM contact_inquiries WHERE 1=1";
    $params = [];

    if ($search !== '') {
        $sql   .= " AND (name LIKE ? OR email LIKE ? OR phone LIKE ? OR subject LIKE ? OR reference_number LIKE ?)";
        $term   = '%' . $search . '%';
        $params = [$term, $term, $term, $term, $term];
    }

    if ($status_filter !== '') {
        $sql     .= " AND status = ?";
        $params[] = $status_filter;
    }

    $sql .= " ORDER BY created_at DESC";

    $stmt      = $pdo->prepare($sql);
    $stmt->execute($params);
    $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = 'Error fetching inquiries: ' . $e->getMessage();
}

// Status counts for tabs
$status_counts = ['new' => 0, 'read' => 0, 'replied' => 0, 'archived' => 0];
try {
    $rows = $pdo->query("SELECT status, COUNT(*) c FROM contact_inquiries GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $status_counts[$row['status']] = (int)$row['c'];
    }
} catch (PDOException $e) { /* ignore */ }

$total_count = array_sum($status_counts);
$new_count   = $status_counts['new'];

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Inquiries — <?php echo htmlspecialchars($site_name); ?> Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/contact-inquiries.css?v=<?php echo @filemtime(__DIR__ . '/css/contact-inquiries.css'); ?>">
    <script src="js/admin-components.js" defer></script>
    <script src="js/contact-inquiries.js" defer></script>
</head>
<body>

<?php require_once 'includes/admin-header.php'; ?>

<div class="content">

    <?php if ($message): ?>
    <div class="alert alert-success" style="margin-bottom:16px;">
        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:16px;">
        <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
    </div>
    <?php endif; ?>

    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;">
        <h2 class="section-title" style="margin:0;">
            <i class="fas fa-envelope"></i> Contact Inquiries
            <?php if ($new_count > 0): ?>
            <span style="background:#dc3545;color:#fff;font-size:12px;font-weight:600;padding:2px 8px;border-radius:10px;vertical-align:middle;margin-left:6px;"><?php echo $new_count; ?> new</span>
            <?php endif; ?>
        </h2>
        <?php if ($new_count > 0): ?>
        <form method="post" style="margin:0;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="inquiry_action" value="mark_all_read">
            <button type="submit" class="btn btn-outline-secondary btn-sm" onclick="return confirm('Mark all new inquiries as read?')">
                <i class="fas fa-check-double"></i> Mark All Read
            </button>
        </form>
        <?php endif; ?>
    </div>

    <!-- Stats row -->
    <div class="stats-row">
        <div class="stat-mini new-card">
            <div class="val"><?php echo $status_counts['new']; ?></div>
            <div class="lbl">New / Unread</div>
        </div>
        <div class="stat-mini read-card">
            <div class="val"><?php echo $status_counts['read']; ?></div>
            <div class="lbl">Read</div>
        </div>
        <div class="stat-mini replied-card">
            <div class="val"><?php echo $status_counts['replied']; ?></div>
            <div class="lbl">Replied</div>
        </div>
        <div class="stat-mini archived-card">
            <div class="val"><?php echo $status_counts['archived']; ?></div>
            <div class="lbl">Archived</div>
        </div>
    </div>

    <!-- Search & filter bar -->
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:16px;align-items:center;">
        <input type="text" name="search" class="form-control" placeholder="Search name, email, subject, reference..." value="<?php echo htmlspecialchars($search); ?>" style="flex:1;min-width:220px;max-width:380px;">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
        <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> Search</button>
        <?php if ($search || $status_filter): ?>
        <a href="contact-inquiries.php" class="btn btn-outline-secondary btn-sm"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>

    <!-- Status filter tabs -->
    <div class="filter-tabs">
        <a href="contact-inquiries.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo $status_filter === '' ? 'active' : ''; ?>">
            All (<?php echo $total_count; ?>)
        </a>
        <a href="contact-inquiries.php?status=new<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo $status_filter === 'new' ? 'active' : ''; ?>">
            <i class="fas fa-circle" style="font-size:8px;color:#dc3545;"></i> New (<?php echo $status_counts['new']; ?>)
        </a>
        <a href="contact-inquiries.php?status=read<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo $status_filter === 'read' ? 'active' : ''; ?>">
            Read (<?php echo $status_counts['read']; ?>)
        </a>
        <a href="contact-inquiries.php?status=replied<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo $status_filter === 'replied' ? 'active' : ''; ?>">
            Replied (<?php echo $status_counts['replied']; ?>)
        </a>
        <a href="contact-inquiries.php?status=archived<?php echo $search ? '&search=' . urlencode($search) : ''; ?>" class="filter-tab <?php echo $status_filter === 'archived' ? 'active' : ''; ?>">
            Archived (<?php echo $status_counts['archived']; ?>)
        </a>
    </div>

    <!-- Inquiry list -->
    <?php if (empty($inquiries)): ?>
    <div class="empty-state">
        <i class="fas fa-envelope-open-text"></i>
        <p>No inquiries found<?php echo $status_filter ? ' with status "' . htmlspecialchars($status_filter) . '"' : ''; ?>.</p>
    </div>
    <?php else: ?>

    <?php foreach ($inquiries as $inq): ?>
    <div class="inquiry-card status-<?php echo htmlspecialchars($inq['status']); ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:8px;">
            <div>
                <div class="inquiry-subject"><?php echo htmlspecialchars($inq['subject']); ?></div>
                <div class="inquiry-meta">
                    <span><i class="fas fa-tag" style="color:#8A775F;"></i> <strong><?php echo htmlspecialchars($inq['reference_number']); ?></strong></span>
                    <span><i class="fas fa-user"></i> <strong><?php echo htmlspecialchars($inq['name']); ?></strong></span>
                    <span><i class="fas fa-envelope"></i> <a href="mailto:<?php echo htmlspecialchars($inq['email']); ?>"><?php echo htmlspecialchars($inq['email']); ?></a></span>
                    <?php if ($inq['phone']): ?>
                    <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($inq['phone']); ?></span>
                    <?php endif; ?>
                    <span><i class="fas fa-clock"></i> <?php echo date('M j, Y g:ia', strtotime($inq['created_at'])); ?></span>
                </div>
            </div>
            <span class="badge badge-<?php echo htmlspecialchars($inq['status']); ?>">
                <?php echo htmlspecialchars($inq['status']); ?>
            </span>
        </div>

        <div class="inquiry-message"><?php echo htmlspecialchars($inq['message']); ?></div>

        <?php
        $reply_payload = [
            'id'        => (int)$inq['id'],
            'reference' => (string)($inq['reference_number'] ?? ''),
            'name'      => (string)($inq['name'] ?? ''),
            'email'     => (string)($inq['email'] ?? ''),
            'subject'   => (string)($inq['subject'] ?? ''),
            'message'   => (string)($inq['message'] ?? ''),
        ];
        $reply_payload_json = json_encode($reply_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        if ($reply_payload_json === false) {
            $reply_payload_json = '{}';
        }
        ?>

        <div class="inquiry-actions">
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="inquiry_action" value="update_status">
                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                <?php if ($inq['status'] === 'new'): ?>
                <input type="hidden" name="new_status" value="read">
                <button type="submit" class="btn btn-sm" style="background:#ffc107;color:#333;border:none;padding:5px 12px;border-radius:5px;cursor:pointer;">
                    <i class="fas fa-eye"></i> Mark Read
                </button>
                <?php endif; ?>
                <?php if ($inq['status'] !== 'replied'): ?>
                <input type="hidden" name="new_status" value="replied">
                <button type="submit" class="btn btn-sm" style="background:#28a745;color:#fff;border:none;padding:5px 12px;border-radius:5px;cursor:pointer;<?php echo $inq['status'] === 'new' ? 'display:none' : ''; ?>">
                    <i class="fas fa-reply"></i> Mark Replied
                </button>
                <?php endif; ?>
            </form>

            <!-- Separate form for each status action to avoid input conflicts -->
            <?php if ($inq['status'] === 'new'): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="inquiry_action" value="update_status">
                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                <input type="hidden" name="new_status" value="replied">
                <button type="submit" class="btn btn-sm" style="background:#28a745;color:#fff;border:none;padding:5px 12px;border-radius:5px;cursor:pointer;">
                    <i class="fas fa-reply"></i> Mark Replied
                </button>
            </form>
            <?php endif; ?>

            <?php if ($inq['status'] !== 'archived'): ?>
            <form method="post" style="display:inline;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="inquiry_action" value="update_status">
                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                <input type="hidden" name="new_status" value="archived">
                <button type="submit" class="btn btn-sm" style="background:#6c757d;color:#fff;border:none;padding:5px 12px;border-radius:5px;cursor:pointer;">
                    <i class="fas fa-archive"></i> Archive
                </button>
            </form>
            <?php endif; ?>

            <button type="button"
                    class="btn btn-sm btn-primary contact-inquiry__reply-btn"
                    data-reply-inquiry="<?php echo htmlspecialchars($reply_payload_json, ENT_QUOTES, 'UTF-8'); ?>"
                    <?php echo $hotel_reply_email_valid ? '' : 'disabled title="Configure hotel sender email first"'; ?>>
                <i class="fas fa-envelope"></i> Reply via Email
            </button>

            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this inquiry? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="inquiry_action" value="delete">
                <input type="hidden" name="inquiry_id" value="<?php echo (int)$inq['id']; ?>">
                <button type="submit" class="btn btn-sm" style="background:#dc3545;color:#fff;border:none;padding:5px 12px;border-radius:5px;cursor:pointer;">
                    <i class="fas fa-trash"></i> Delete
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <?php endif; ?>

</div><!-- /.content -->

<?php
require_once '../includes/modal.php';

ob_start();
?>
<form method="post" id="contactInquiryReplyForm" class="contact-reply-modal" data-admin-loading-form data-site-name="<?php echo htmlspecialchars($site_name, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <input type="hidden" name="inquiry_action" value="reply_email">
    <input type="hidden" name="inquiry_id" id="replyInquiryId" value="">

    <div class="contact-reply-modal__from <?php echo $hotel_reply_email_valid ? '' : 'contact-reply-modal__from--invalid'; ?>">
        <span class="contact-reply-modal__label">Sending from</span>
        <strong><?php echo htmlspecialchars($hotel_reply_email ?: 'Hotel email not configured'); ?></strong>
    </div>

    <div class="contact-reply-modal__recipient">
        <span class="contact-reply-modal__label">Recipient</span>
        <strong id="replyRecipientName">Guest</strong>
        <span id="replyRecipientEmail"></span>
    </div>

    <label class="contact-reply-modal__field" for="replySubject">
        <span>Subject</span>
        <input type="text" name="reply_subject" id="replySubject" maxlength="255" required>
    </label>

    <label class="contact-reply-modal__field" for="replyMessage">
        <span>Message</span>
        <textarea name="reply_message" id="replyMessage" maxlength="5000" required></textarea>
    </label>

    <div class="contact-reply-modal__original">
        <span class="contact-reply-modal__label">Original inquiry</span>
        <div class="contact-reply-modal__reference" id="replyReference"></div>
        <p id="replyOriginalMessage"></p>
    </div>
</form>
<?php
$reply_modal_body = ob_get_clean();

ob_start();
?>
<button type="button" class="btn btn-secondary" data-modal-close>Cancel</button>
<button type="submit" form="contactInquiryReplyForm" class="btn btn-primary" <?php echo $hotel_reply_email_valid ? '' : 'disabled'; ?>>
    <i class="fas fa-paper-plane"></i> Send Reply
</button>
<?php
$reply_modal_footer = ob_get_clean();

renderModal('contactInquiryReplyModal', '<i class="fas fa-envelope"></i> Reply to Inquiry', $reply_modal_body, [
    'size' => 'lg',
    'footer' => $reply_modal_footer,
]);
?>

<div id="admin-page-loader" class="admin-page-loader" role="status" aria-label="Loading">
  <div class="admin-page-loader-card">
    <div class="admin-page-loader-spinner"><span></span><span></span><span></span></div>
    <p class="admin-page-loader-title">Sending reply...</p>
  </div>
</div>

<?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>

