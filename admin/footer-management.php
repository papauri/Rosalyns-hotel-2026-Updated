<?php
/**
 * Footer Management
 * Admin interface for managing footer links, policies, and footer settings.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */
require_once '../includes/alert.php';
require_once 'includes/admin-modal.php';

if (!hasPermission((int)$user['id'], 'footer_management')) {
    header('Location: dashboard.php?error=access_denied');
    exit;
}

$message = '';
$error   = '';

// ─── Ensure tables exist ────────────────────────────────────────────────────
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS footer_links (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            column_name   VARCHAR(100) NOT NULL,
            link_text     VARCHAR(200) NOT NULL,
            link_url      VARCHAR(500) NOT NULL DEFAULT '',
            secondary_link_url VARCHAR(500) DEFAULT NULL,
            display_order INT UNSIGNED DEFAULT 0,
            is_active     TINYINT(1) NOT NULL DEFAULT 1,
            created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_active_order (is_active, column_name, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS policies (
            id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            slug          VARCHAR(100) NOT NULL,
            title         VARCHAR(200) NOT NULL,
            summary       TEXT DEFAULT NULL,
            content       LONGTEXT NOT NULL,
            display_order INT UNSIGNED DEFAULT 0,
            is_active     TINYINT(1) NOT NULL DEFAULT 1,
            updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_slug (slug),
            INDEX idx_active (is_active, display_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
} catch (PDOException $e) {
    $error = 'Table setup error: ' . $e->getMessage();
}

// ─── POST Handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        $action = trim($_POST['action'] ?? '');

        try {
            // ── Footer Links ────────────────────────────────────────────────
            if ($action === 'add_link') {
                $col   = trim($_POST['column_name'] ?? '');
                $text  = trim($_POST['link_text'] ?? '');
                $url   = trim($_POST['link_url'] ?? '');
                $sec   = trim($_POST['secondary_link_url'] ?? '') ?: null;
                $order = max(0, (int)($_POST['display_order'] ?? 0));

                if (!$col || !$text) {
                    throw new Exception('Column name and link text are required.');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO footer_links (column_name, link_text, link_url, secondary_link_url, display_order, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$col, $text, $url, $sec, $order]);
                $message = 'Footer link added.';
                if (function_exists('clearCache')) clearCache();
                rh_log_event('footer_management', 'info', 'Footer link added', ['link_text' => $text, 'by' => $user['username']]);

            } elseif ($action === 'edit_link') {
                $id    = (int)($_POST['link_id'] ?? 0);
                $col   = trim($_POST['column_name'] ?? '');
                $text  = trim($_POST['link_text'] ?? '');
                $url   = trim($_POST['link_url'] ?? '');
                $sec   = trim($_POST['secondary_link_url'] ?? '') ?: null;
                $order = max(0, (int)($_POST['display_order'] ?? 0));
                $active = (int)(!empty($_POST['is_active']));

                if ($id <= 0 || !$col || !$text) {
                    throw new Exception('Column name and link text are required.');
                }

                $stmt = $pdo->prepare("
                    UPDATE footer_links
                    SET column_name = ?, link_text = ?, link_url = ?,
                        secondary_link_url = ?, display_order = ?, is_active = ?
                    WHERE id = ?
                ");
                $stmt->execute([$col, $text, $url, $sec, $order, $active, $id]);
                $message = 'Footer link updated.';
                if (function_exists('clearCache')) clearCache();
                rh_log_event('footer_management', 'info', 'Footer link updated', ['id' => $id, 'by' => $user['username']]);

            } elseif ($action === 'delete_link') {
                $id = (int)($_POST['link_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid link ID.');
                }
                $pdo->prepare("DELETE FROM footer_links WHERE id = ?")->execute([$id]);
                $message = 'Footer link deleted.';
                if (function_exists('clearCache')) clearCache();
                rh_log_event('footer_management', 'info', 'Footer link deleted', ['id' => $id, 'by' => $user['username']]);

            } elseif ($action === 'toggle_link') {
                $id = (int)($_POST['link_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid link ID.');
                }
                $pdo->prepare("UPDATE footer_links SET is_active = NOT is_active WHERE id = ?")->execute([$id]);
                $message = 'Footer link visibility toggled.';

            // ── Policies ────────────────────────────────────────────────────
            } elseif ($action === 'add_policy') {
                $slug    = trim((string)preg_replace('/[^a-z0-9-]/', '-', strtolower($_POST['slug'] ?? '')));
                $title   = trim($_POST['title'] ?? '');
                $summary = trim($_POST['summary'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $order   = max(0, (int)($_POST['display_order'] ?? 0));

                if (!$slug || !$title || !$content) {
                    throw new Exception('Slug, title, and content are required.');
                }

                $chk = $pdo->prepare("SELECT COUNT(*) FROM policies WHERE slug = ?");
                $chk->execute([$slug]);
                if ($chk->fetchColumn() > 0) {
                    throw new Exception('A policy with that slug already exists.');
                }

                $stmt = $pdo->prepare("
                    INSERT INTO policies (slug, title, summary, content, display_order, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([$slug, $title, $summary, $content, $order]);
                $message = "Policy \"$title\" added.";
                rh_log_event('footer_management', 'info', 'Policy added', ['slug' => $slug, 'by' => $user['username']]);

            } elseif ($action === 'edit_policy') {
                $id      = (int)($_POST['policy_id'] ?? 0);
                $title   = trim($_POST['title'] ?? '');
                $summary = trim($_POST['summary'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $order   = max(0, (int)($_POST['display_order'] ?? 0));
                $active  = (int)(!empty($_POST['is_active']));

                if ($id <= 0 || !$title || !$content) {
                    throw new Exception('Title and content are required.');
                }

                $stmt = $pdo->prepare("
                    UPDATE policies
                    SET title = ?, summary = ?, content = ?, display_order = ?, is_active = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$title, $summary, $content, $order, $active, $id]);
                $message = "Policy \"$title\" updated.";
                rh_log_event('footer_management', 'info', 'Policy updated', ['id' => $id, 'by' => $user['username']]);

            } elseif ($action === 'delete_policy') {
                $id = (int)($_POST['policy_id'] ?? 0);
                if ($id <= 0) {
                    throw new Exception('Invalid policy ID.');
                }
                $pdo->prepare("DELETE FROM policies WHERE id = ?")->execute([$id]);
                $message = 'Policy deleted.';
                rh_log_event('footer_management', 'info', 'Policy deleted', ['id' => $id, 'by' => $user['username']]);

            // ── Footer Settings ─────────────────────────────────────────────
            } elseif ($action === 'save_footer_settings') {
                $fields = [
                    'phone_main'           => trim($_POST['phone_main'] ?? ''),
                    'email_main'           => trim($_POST['email_main'] ?? ''),
                    'address_line1'        => trim($_POST['address_line1'] ?? ''),
                    'working_hours'        => trim($_POST['working_hours'] ?? ''),
                    'facebook_url'         => trim($_POST['facebook_url'] ?? ''),
                    'instagram_url'        => trim($_POST['instagram_url'] ?? ''),
                    'twitter_url'          => trim($_POST['twitter_url'] ?? ''),
                    'linkedin_url'         => trim($_POST['linkedin_url'] ?? ''),
                    'footer_credits'       => trim($_POST['footer_credits'] ?? ''),
                    'footer_design_credit' => trim($_POST['footer_design_credit'] ?? ''),
                ];

                if (!empty($fields['email_main']) && !filter_var($fields['email_main'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Invalid email address.');
                }
                foreach (['facebook_url','instagram_url','twitter_url','linkedin_url'] as $urlField) {
                    if (!empty($fields[$urlField]) && !filter_var($fields[$urlField], FILTER_VALIDATE_URL)) {
                        throw new Exception('Invalid URL in social media fields.');
                    }
                }

                foreach ($fields as $k => $v) {
                    updateSetting($k, $v);
                }
                // Bust site settings cache
                if (function_exists('clearCache')) {
                    clearCache();
                }
                $message = 'Footer settings saved.';
                rh_log_event('footer_management', 'info', 'Footer settings updated', ['by' => $user['username']]);

            } else {
                throw new Exception('Unknown action.');
            }

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// ─── Load data ───────────────────────────────────────────────────────────────
$footer_links = [];
try {
    $rows = $pdo->query("SELECT * FROM footer_links ORDER BY column_name ASC, display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $footer_links[$row['column_name']][] = $row;
    }
} catch (PDOException $e) {
    $error = $error ?: 'Could not load footer links.';
}

$policies = [];
try {
    $policies = $pdo->query("SELECT * FROM policies ORDER BY display_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = $error ?: 'Could not load policies.';
}

$all_columns = [];
foreach ($footer_links as $col => $links) {
    $all_columns[] = $col;
}

// Footer settings
$fs = [
    'phone_main'           => getSetting('phone_main', ''),
    'email_main'           => getSetting('email_main', ''),
    'address_line1'        => getSetting('address_line1', ''),
    'working_hours'        => getSetting('working_hours', ''),
    'facebook_url'         => getSetting('facebook_url', ''),
    'instagram_url'        => getSetting('instagram_url', ''),
    'twitter_url'          => getSetting('twitter_url', ''),
    'linkedin_url'         => getSetting('linkedin_url', ''),
    'footer_credits'       => getSetting('footer_credits', ''),
    'footer_design_credit' => getSetting('footer_design_credit', ''),
];

$site_name = getSetting('site_name', 'Admin');
$active_tab = $_GET['tab'] ?? 'links';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Footer Management — <?php echo htmlspecialchars($site_name); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .fm-tabs { display:flex; gap:0; border-bottom:2px solid var(--border-color,#e5e7eb); margin-bottom:24px; flex-wrap:wrap; }
        .fm-tab  { padding:12px 24px; cursor:pointer; font-weight:500; font-size:14px; border-bottom:3px solid transparent; margin-bottom:-2px; color:var(--text-secondary,#6b7280); text-decoration:none; display:flex; align-items:center; gap:8px; transition:all .18s; }
        .fm-tab:hover { color:var(--primary,#8A775F); }
        .fm-tab--active { color:var(--primary,#8A775F); border-bottom-color:var(--primary,#8A775F); }
        .fm-section { background:#fff; border-radius:8px; border:1px solid var(--border-color,#e5e7eb); padding:24px; margin-bottom:24px; }
        .fm-section h3 { font-size:16px; font-weight:600; margin:0 0 16px; display:flex; align-items:center; gap:8px; color:var(--text-primary,#111); }
        .fm-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:16px; }
        .fm-col-block { background:var(--bg-subtle,#f9fafb); border:1px solid var(--border-color,#e5e7eb); border-radius:8px; padding:16px; }
        .fm-col-block h4 { font-size:13px; font-weight:600; color:var(--text-secondary,#6b7280); text-transform:uppercase; letter-spacing:.06em; margin:0 0 12px; display:flex; justify-content:space-between; align-items:center; }
        .fm-link-row { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid var(--border-color,#e5e7eb); font-size:13px; }
        .fm-link-row:last-child { border-bottom:none; }
        .fm-link-row .link-name { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fm-link-row .link-url  { font-size:11px; color:var(--text-secondary,#6b7280); max-width:120px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fm-link-row .badge-off { font-size:10px; background:#fef2f2; color:#dc2626; border-radius:4px; padding:1px 6px; }
        .fm-btn { padding:5px 10px; border:1px solid var(--border-color,#e5e7eb); border-radius:5px; background:#fff; cursor:pointer; font-size:12px; color:var(--text-secondary,#6b7280); transition:all .15s; }
        .fm-btn:hover { background:var(--bg-subtle,#f3f4f6); }
        .fm-btn--danger { color:#dc2626; border-color:#fca5a5; }
        .fm-btn--danger:hover { background:#fef2f2; }
        .fm-btn--primary { background:var(--primary,#8A775F); color:#fff; border-color:var(--primary,#8A775F); }
        .fm-btn--primary:hover { opacity:.88; }
        .policy-card { border:1px solid var(--border-color,#e5e7eb); border-radius:8px; padding:16px; background:#fff; }
        .policy-card h4 { margin:0 0 6px; font-size:15px; font-weight:600; }
        .policy-card .policy-slug { font-size:11px; color:var(--text-secondary,#6b7280); font-family:monospace; background:var(--bg-subtle,#f3f4f6); padding:2px 6px; border-radius:4px; }
        .policy-card .policy-summary { font-size:13px; color:var(--text-secondary,#6b7280); margin:8px 0; line-height:1.5; }
        .policy-card .policy-actions { display:flex; gap:8px; margin-top:12px; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        @media(max-width:600px){ .form-row { grid-template-columns:1fr; } }
        .form-group label { display:block; font-size:13px; font-weight:500; margin-bottom:5px; color:var(--text-primary,#111); }
        .form-group input, .form-group textarea, .form-group select { width:100%; padding:8px 10px; border:1px solid var(--border-color,#e5e7eb); border-radius:6px; font-size:13px; font-family:inherit; }
        .form-group textarea { resize:vertical; min-height:120px; }
        .form-group .help-text { font-size:11px; color:var(--text-secondary,#6b7280); margin-top:4px; }
        .modal-body .form-group { margin-bottom:14px; }
        .modal-actions { display:flex; gap:10px; justify-content:flex-end; margin-top:18px; padding-top:14px; border-top:1px solid var(--border-color,#e5e7eb); }
        .tab-panel { display:none; }
        .tab-panel--active { display:block; }
        .settings-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media(max-width:700px){ .settings-grid { grid-template-columns:1fr; } }
        .add-col-btn { font-size:12px; padding:4px 10px; }

        /* DB Tags panel */
        .fm-tags-panel { border:1px solid var(--border-color,#e5e7eb); border-radius:8px; margin-bottom:20px; overflow:hidden; }
        .fm-tags-panel__header { display:flex; justify-content:space-between; align-items:center; padding:10px 16px; background:var(--bg-subtle,#f9fafb); cursor:pointer; font-size:13px; font-weight:500; color:var(--text-primary,#111); user-select:none; }
        .fm-tags-panel__header:hover { background:#f0f0f0; }
        .fm-tags-panel__arrow { transition:transform .2s; }
        .fm-tags-panel--open .fm-tags-panel__arrow { transform:rotate(180deg); }
        .fm-tags-panel__body { display:none; padding:14px 16px; border-top:1px solid var(--border-color,#e5e7eb); }
        .fm-tags-panel--open .fm-tags-panel__body { display:block; }
        .fm-tags-panel__hint { font-size:12px; color:var(--text-secondary,#6b7280); margin:0 0 12px; }
        .fm-tags-list { display:flex; flex-wrap:wrap; gap:8px; }
        .fm-tag-chip { display:inline-flex; flex-direction:column; align-items:flex-start; gap:1px; padding:6px 10px; border:1px solid var(--border-color,#e5e7eb); border-radius:6px; background:#fff; cursor:pointer; text-align:left; transition:all .15s; }
        .fm-tag-chip:hover { background:var(--primary,#8A775F); border-color:var(--primary,#8A775F); color:#fff; }
        .fm-tag-chip:hover .fm-tag-chip__label,
        .fm-tag-chip:hover .fm-tag-chip__live { color:#fff; opacity:.85; }
        .fm-tag-chip code { font-size:12px; font-family:monospace; color:var(--primary,#8A775F); font-weight:600; }
        .fm-tag-chip:hover code { color:#fff; }
        .fm-tag-chip__label { font-size:10px; color:var(--text-secondary,#6b7280); }
        .fm-tag-chip__live  { font-size:10px; color:var(--text-secondary,#9ca3af); font-style:italic; max-width:140px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

        /* Tag autocomplete dropdown */
        .fm-autocomplete { position:absolute; z-index:9999; background:#fff; border:1px solid var(--border-color,#e5e7eb); border-radius:8px; box-shadow:0 8px 28px rgba(0,0,0,.13); display:none; max-height:300px; overflow-y:auto; }
        .fm-autocomplete__item { display:grid; grid-template-columns:auto 1fr auto; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; font-size:13px; border-bottom:1px solid #f3f4f6; }
        .fm-autocomplete__item:last-child { border-bottom:none; }
        .fm-autocomplete__item code { font-family:monospace; font-size:12px; color:var(--primary,#8A775F); font-weight:600; white-space:nowrap; }
        .fm-autocomplete__item--active,
        .fm-autocomplete__item:hover { background:var(--primary,#8A775F); color:#fff; }
        .fm-autocomplete__item--active code,
        .fm-autocomplete__item:hover code { color:#fff; }
        .fm-autocomplete__label { font-size:12px; color:var(--text-secondary,#6b7280); }
        .fm-autocomplete__item--active .fm-autocomplete__label,
        .fm-autocomplete__item:hover .fm-autocomplete__label { color:rgba(255,255,255,.8); }
        .fm-autocomplete__live { font-size:11px; color:#9ca3af; font-style:italic; text-align:right; max-width:130px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .fm-autocomplete__item--active .fm-autocomplete__live,
        .fm-autocomplete__item:hover .fm-autocomplete__live { color:rgba(255,255,255,.6); }
        /* Tag hint line */
        .fm-tag-hint { font-size:12px; color:var(--text-secondary,#6b7280); margin-top:5px; display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
        .fm-tag-hint code { color:var(--primary,#8A775F); background:var(--bg-subtle,#f3f4f6); padding:0 4px; border-radius:3px; font-size:11px; }
    </style>
</head>
<body>
<?php require_once 'includes/admin-header.php'; ?>

<div class="content">
    <div class="page-header">
        <h2 class="page-title"><i class="fas fa-shoe-prints" style="transform:rotate(-90deg);"></i>&nbsp; Footer Management</h2>
        <p class="text-muted">Manage footer links, policies, and contact / social settings</p>
    </div>

    <?php if ($message): ?><?php showAlert($message, 'success'); ?><?php endif; ?>
    <?php if ($error):   ?><?php showAlert($error,   'error');   ?><?php endif; ?>

    <!-- DB Tags Reference ─────────────────────────────────────────────── -->
    <div class="fm-tags-panel" id="fmTagsPanel">
        <div class="fm-tags-panel__header" onclick="document.getElementById('fmTagsPanel').classList.toggle('fm-tags-panel--open')">
            <span><i class="fas fa-tags"></i> DB Tags — click a tag to insert it at your cursor</span>
            <i class="fas fa-chevron-down fm-tags-panel__arrow"></i>
        </div>
        <div class="fm-tags-panel__body">
            <p class="fm-tags-panel__hint">Use these in any text field (credits, policy content, etc.). They are replaced with live values when the footer renders.</p>
            <div class="fm-tags-list">
                <?php
                $available_tags = [
                    // Identity
                    ['tag' => '{{site_name}}',           'label' => 'Site Name',            'live' => getSetting('site_name', '—')],
                    ['tag' => '{{site_tagline}}',        'label' => 'Tagline',              'live' => getSetting('site_tagline', '—')],
                    ['tag' => '{{hotel_star_rating}}',   'label' => 'Star Rating',          'live' => getSetting('hotel_star_rating', '—')],
                    ['tag' => '{{year}}',                'label' => 'Current Year',         'live' => date('Y')],
                    // Contact
                    ['tag' => '{{phone}}',               'label' => 'Phone (main)',         'live' => getSetting('phone_main', '—')],
                    ['tag' => '{{phone_reservations}}',  'label' => 'Phone (reservations)', 'live' => getSetting('phone_reservations', '—')],
                    ['tag' => '{{email}}',               'label' => 'Email (main)',         'live' => getSetting('email_main', '—')],
                    ['tag' => '{{email_reservations}}',  'label' => 'Email (reservations)', 'live' => getSetting('email_reservations', '—')],
                    // Location
                    ['tag' => '{{address}}',             'label' => 'Address line 1',       'live' => getSetting('address_line1', '—')],
                    ['tag' => '{{address_line2}}',       'label' => 'Address line 2',       'live' => getSetting('address_line2', '—')],
                    ['tag' => '{{address_country}}',     'label' => 'Country',              'live' => getSetting('address_country', '—')],
                    ['tag' => '{{working_hours}}',       'label' => 'Working Hours',        'live' => getSetting('working_hours', '—')],
                    // Booking
                    ['tag' => '{{check_in_time}}',       'label' => 'Check-in Time',        'live' => getSetting('check_in_time', '—')],
                    ['tag' => '{{check_out_time}}',      'label' => 'Check-out Time',       'live' => getSetting('check_out_time', '—')],
                    ['tag' => '{{payment_policy}}',      'label' => 'Payment Policy',       'live' => getSetting('payment_policy', '—')],
                    ['tag' => '{{cancellation_policy}}', 'label' => 'Cancellation Policy',  'live' => getSetting('cancellation_policy', '—')],
                    // Finance
                    ['tag' => '{{currency}}',            'label' => 'Currency Code',        'live' => getSetting('currency_code', '—')],
                    ['tag' => '{{vat_rate}}',            'label' => 'VAT Rate (%)',         'live' => getSetting('vat_rate', '—')],
                    // Social
                    ['tag' => '{{facebook_url}}',        'label' => 'Facebook URL',         'live' => getSetting('facebook_url', '—')],
                    ['tag' => '{{instagram_url}}',       'label' => 'Instagram URL',        'live' => getSetting('instagram_url', '—')],
                    ['tag' => '{{twitter_url}}',         'label' => 'Twitter URL',          'live' => getSetting('twitter_url', '—')],
                    ['tag' => '{{linkedin_url}}',        'label' => 'LinkedIn URL',         'live' => getSetting('linkedin_url', '—')],
                ];
                foreach ($available_tags as $t):
                ?>
                <button type="button" class="fm-tag-chip"
                    data-tag="<?php echo htmlspecialchars($t['tag']); ?>"
                    title="Resolves to: <?php echo htmlspecialchars($t['live']); ?>">
                    <code><?php echo htmlspecialchars($t['tag']); ?></code>
                    <span class="fm-tag-chip__label"><?php echo htmlspecialchars($t['label']); ?></span>
                    <span class="fm-tag-chip__live"><?php echo htmlspecialchars(mb_substr($t['live'], 0, 30)) . (mb_strlen($t['live']) > 30 ? '…' : ''); ?></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <nav class="fm-tabs">
        <a href="?tab=links"    class="fm-tab <?php echo $active_tab === 'links'    ? 'fm-tab--active' : ''; ?>"><i class="fas fa-link"></i> Footer Links</a>
        <a href="?tab=policies" class="fm-tab <?php echo $active_tab === 'policies' ? 'fm-tab--active' : ''; ?>"><i class="fas fa-file-contract"></i> Policies &amp; Modals</a>
        <a href="?tab=settings" class="fm-tab <?php echo $active_tab === 'settings' ? 'fm-tab--active' : ''; ?>"><i class="fas fa-cog"></i> Contact &amp; Social</a>
    </nav>

    <!-- ═══════════════════════════════════════════════════════ TAB: LINKS -->
    <div class="tab-panel <?php echo $active_tab === 'links' ? 'tab-panel--active' : ''; ?>">

        <div class="fm-section">
            <h3><i class="fas fa-th-list"></i> Footer Link Columns</h3>
            <?php if (empty($footer_links)): ?>
                <p style="color:#888;">No footer links yet. Add your first link below.</p>
            <?php else: ?>
            <div class="fm-grid">
                <?php foreach ($footer_links as $col_name => $links): ?>
                <div class="fm-col-block">
                    <h4>
                        <span><?php echo htmlspecialchars($col_name); ?></span>
                        <button type="button" class="fm-btn fm-btn--primary add-col-btn"
                            onclick="openAddLink(<?php echo htmlspecialchars(json_encode($col_name), ENT_QUOTES); ?>)">
                            <i class="fas fa-plus"></i> Add
                        </button>
                    </h4>
                    <?php foreach ($links as $lnk): ?>
                    <div class="fm-link-row">
                        <span class="link-name" title="<?php echo htmlspecialchars($lnk['link_text']); ?>"><?php echo htmlspecialchars($lnk['link_text']); ?></span>
                        <?php if (!$lnk['is_active']): ?>
                            <span class="badge-off">hidden</span>
                        <?php endif; ?>
                        <span class="link-url" title="<?php echo htmlspecialchars($lnk['link_url']); ?>"><?php echo htmlspecialchars($lnk['link_url']); ?></span>
                        <button type="button" class="fm-btn" title="Edit"
                            onclick='openEditLink(<?php echo htmlspecialchars(json_encode($lnk), ENT_QUOTES, "UTF-8"); ?>)'>
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <button type="button" class="fm-btn fm-btn--danger" title="Delete"
                            onclick="openDeleteLink(<?php echo (int)$lnk['id']; ?>, <?php echo htmlspecialchars(json_encode($lnk['link_text']), ENT_QUOTES); ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="fm-section">
            <h3><i class="fas fa-plus-circle"></i> Add New Footer Link</h3>
            <form method="POST" action="footer-management.php?tab=links">
                <input type="hidden" name="action" value="add_link">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Column Name <span style="color:#dc3545">*</span></label>
                        <input type="text" name="column_name" placeholder="e.g. Guest Services" required list="col-datalist">
                        <datalist id="col-datalist">
                            <?php foreach ($all_columns as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>">
                            <?php endforeach; ?>
                        </datalist>
                        <p class="help-text">Type an existing column to add to it, or a new name to create a new column.</p>
                    </div>
                    <div class="form-group">
                        <label>Link Text <span style="color:#dc3545">*</span></label>
                        <input type="text" name="link_text" placeholder="e.g. Spa & Wellness" required>
                    </div>
                    <div class="form-group">
                        <label>Link URL (Home page / index)</label>
                        <input type="text" name="link_url" placeholder="e.g. #spa or spa.php">
                        <p class="help-text">Used on the home page. Hash links (e.g. #spa) scroll to sections.</p>
                    </div>
                    <div class="form-group">
                        <label>Secondary URL (other pages)</label>
                        <input type="text" name="secondary_link_url" placeholder="e.g. spa.php">
                        <p class="help-text">Used on all pages other than the home page. Leave blank to reuse primary URL.</p>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" value="10" min="0" max="9999">
                    </div>
                </div>
                <button type="submit" class="fm-btn fm-btn--primary" style="margin-top:8px;">
                    <i class="fas fa-plus"></i> Add Link
                </button>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════ TAB: POLICIES -->
    <div class="tab-panel <?php echo $active_tab === 'policies' ? 'tab-panel--active' : ''; ?>">

        <div class="fm-section">
            <h3><i class="fas fa-file-contract"></i> Policies &amp; Modal Content</h3>
            <p style="font-size:13px;color:#888;margin:-8px 0 16px;">These policies appear as pop-up modals in the footer. Each policy has a slug that links it to the footer &ldquo;Policies&rdquo; column.</p>
            <?php if (empty($policies)): ?>
                <p style="color:#888;">No policies yet. Add one below.</p>
            <?php else: ?>
            <div class="fm-grid">
                <?php foreach ($policies as $pol): ?>
                <div class="policy-card">
                    <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:8px;">
                        <div>
                            <h4><?php echo htmlspecialchars($pol['title']); ?></h4>
                            <span class="policy-slug"><?php echo htmlspecialchars($pol['slug']); ?></span>
                        </div>
                        <?php if (!$pol['is_active']): ?>
                            <span style="font-size:11px;background:#fef2f2;color:#dc2626;border-radius:4px;padding:2px 8px;white-space:nowrap;">Hidden</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($pol['summary']): ?>
                        <p class="policy-summary"><?php echo htmlspecialchars(mb_substr($pol['summary'], 0, 100)) . (mb_strlen($pol['summary']) > 100 ? '…' : ''); ?></p>
                    <?php endif; ?>
                    <div class="policy-actions">
                        <button type="button" class="fm-btn fm-btn--primary"
                            onclick='openEditPolicy(<?php echo htmlspecialchars(json_encode($pol), ENT_QUOTES, "UTF-8"); ?>)'>
                            <i class="fas fa-pencil-alt"></i> Edit
                        </button>
                        <button type="button" class="fm-btn fm-btn--danger"
                            onclick="openDeletePolicy(<?php echo (int)$pol['id']; ?>, <?php echo htmlspecialchars(json_encode($pol['title']), ENT_QUOTES); ?>)">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="fm-section">
            <h3><i class="fas fa-plus-circle"></i> Add New Policy</h3>
            <form method="POST" action="footer-management.php?tab=policies">
                <input type="hidden" name="action" value="add_policy">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="form-row">
                    <div class="form-group">
                        <label>Policy Slug <span style="color:#dc3545">*</span></label>
                        <input type="text" name="slug" placeholder="e.g. booking-policy" required
                               pattern="[a-z0-9-]+" title="Lowercase letters, numbers and hyphens only">
                        <p class="help-text">Unique identifier used to link the footer link to this modal.</p>
                    </div>
                    <div class="form-group">
                        <label>Title <span style="color:#dc3545">*</span></label>
                        <input type="text" name="title" placeholder="e.g. Booking Policy" required>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Summary (shown under title in modal)</label>
                        <input type="text" name="summary" placeholder="One-line summary of this policy" data-tag-autocomplete>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;">
                        <label>Content <span style="color:#dc3545">*</span></label>
                        <textarea name="content" placeholder="Full policy text. Use plain text or basic HTML." required style="min-height:180px;" data-tag-autocomplete></textarea>
                        <p class="fm-tag-hint"><i class="fas fa-magic"></i> Type <code>{{</code> to autocomplete a DB tag — e.g. <code>{{check_in_time}}</code>, <code>{{site_name}}</code>, <code>{{phone}}</code>. Tags resolve to live values when the footer renders. Or use the Tags panel above.</p>
                    </div>
                    <div class="form-group">
                        <label>Display Order</label>
                        <input type="number" name="display_order" value="10" min="0" max="9999">
                    </div>
                </div>
                <button type="submit" class="fm-btn fm-btn--primary" style="margin-top:8px;">
                    <i class="fas fa-plus"></i> Add Policy
                </button>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════ TAB: SETTINGS -->
    <div class="tab-panel <?php echo $active_tab === 'settings' ? 'tab-panel--active' : ''; ?>">

        <form method="POST" action="footer-management.php?tab=settings">
            <input type="hidden" name="action" value="save_footer_settings">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div class="fm-section">
                <h3><i class="fas fa-address-card"></i> Contact Information</h3>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Phone Number</label>
                        <input type="text" name="phone_main" value="<?php echo htmlspecialchars($fs['phone_main']); ?>" placeholder="+265 xxx xxx xxx">
                    </div>
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email_main" value="<?php echo htmlspecialchars($fs['email_main']); ?>" placeholder="info@hotel.com">
                    </div>
                    <div class="form-group">
                        <label>Address</label>
                        <input type="text" name="address_line1" value="<?php echo htmlspecialchars($fs['address_line1']); ?>" placeholder="123 Main Street, City">
                    </div>
                    <div class="form-group">
                        <label>Working Hours</label>
                        <input type="text" name="working_hours" value="<?php echo htmlspecialchars($fs['working_hours']); ?>" placeholder="24 Hours / 7 Days a Week">
                    </div>
                </div>
            </div>

            <div class="fm-section">
                <h3><i class="fas fa-share-alt"></i> Social Media Links</h3>
                <div class="settings-grid">
                    <div class="form-group">
                        <label><i class="fab fa-facebook-f" style="color:#1877F2;width:16px;"></i> Facebook URL</label>
                        <input type="url" name="facebook_url" value="<?php echo htmlspecialchars($fs['facebook_url']); ?>" placeholder="https://facebook.com/yourpage">
                    </div>
                    <div class="form-group">
                        <label><i class="fab fa-instagram" style="color:#E4405F;width:16px;"></i> Instagram URL</label>
                        <input type="url" name="instagram_url" value="<?php echo htmlspecialchars($fs['instagram_url']); ?>" placeholder="https://instagram.com/yourhandle">
                    </div>
                    <div class="form-group">
                        <label><i class="fab fa-twitter" style="color:#1DA1F2;width:16px;"></i> Twitter / X URL</label>
                        <input type="url" name="twitter_url" value="<?php echo htmlspecialchars($fs['twitter_url']); ?>" placeholder="https://twitter.com/yourhandle">
                    </div>
                    <div class="form-group">
                        <label><i class="fab fa-linkedin-in" style="color:#0A66C2;width:16px;"></i> LinkedIn URL</label>
                        <input type="url" name="linkedin_url" value="<?php echo htmlspecialchars($fs['linkedin_url']); ?>" placeholder="https://linkedin.com/company/yourpage">
                    </div>
                </div>
            </div>

            <div class="fm-section">
                <h3><i class="fas fa-copyright"></i> Copyright &amp; Credits</h3>
                <div class="settings-grid">
                    <div class="form-group">
                        <label>Copyright Text</label>
                        <input type="text" name="footer_credits" value="<?php echo htmlspecialchars($fs['footer_credits']); ?>" placeholder="© 2026 Hotel Name. All rights reserved.">
                        <p class="help-text">Appears in the bottom-left of the footer.</p>
                    </div>
                    <div class="form-group">
                        <label>Design Credit / Tagline</label>
                        <input type="text" name="footer_design_credit" value="<?php echo htmlspecialchars($fs['footer_design_credit']); ?>" placeholder="Designed with ♥ for Luxury Excellence">
                        <p class="help-text">Appears in the bottom-right of the footer.</p>
                    </div>
                </div>
            </div>

            <button type="submit" class="fm-btn fm-btn--primary" style="font-size:14px; padding:10px 24px;">
                <i class="fas fa-save"></i> Save Footer Settings
            </button>
        </form>
    </div>
</div>

<!-- ═══ Edit Link Modal ═══════════════════════════════════════════════════ -->
<?php renderAdminModalStart('editLinkModal', 'Edit Footer Link'); ?>
<form method="POST" action="footer-management.php?tab=links">
    <input type="hidden" name="action" value="edit_link">
    <input type="hidden" name="link_id" id="el_id">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="form-group">
        <label>Column Name <span style="color:#dc3545">*</span></label>
        <input type="text" name="column_name" id="el_col" required list="col-datalist-modal">
        <datalist id="col-datalist-modal">
            <?php foreach ($all_columns as $c): ?>
                <option value="<?php echo htmlspecialchars($c); ?>">
            <?php endforeach; ?>
        </datalist>
    </div>
    <div class="form-group">
        <label>Link Text <span style="color:#dc3545">*</span></label>
        <input type="text" name="link_text" id="el_text" required>
    </div>
    <div class="form-group">
        <label>Link URL (Home page)</label>
        <input type="text" name="link_url" id="el_url">
    </div>
    <div class="form-group">
        <label>Secondary URL (other pages)</label>
        <input type="text" name="secondary_link_url" id="el_sec">
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Display Order</label>
            <input type="number" name="display_order" id="el_order" min="0" max="9999">
        </div>
        <div class="form-group" style="display:flex; align-items:center; padding-top:22px; gap:8px;">
            <input type="checkbox" name="is_active" id="el_active" value="1" style="width:auto;">
            <label for="el_active" style="margin:0; cursor:pointer;">Visible in footer</label>
        </div>
    </div>
    <div class="modal-actions">
        <button type="button" class="fm-btn" onclick="closeAdminModal('editLinkModal')">Cancel</button>
        <button type="submit" class="fm-btn fm-btn--primary"><i class="fas fa-save"></i> Save</button>
    </div>
</form>
<?php renderAdminModalEnd(); ?>

<!-- ═══ Delete Link Modal ════════════════════════════════════════════════ -->
<?php renderAdminModalStart('deleteLinkModal', 'Delete Footer Link'); ?>
<p>Are you sure you want to delete the link <strong id="dl_name"></strong>? This cannot be undone.</p>
<form method="POST" action="footer-management.php?tab=links" id="deleteLinkForm">
    <input type="hidden" name="action" value="delete_link">
    <input type="hidden" name="link_id" id="dl_id">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="modal-actions">
        <button type="button" class="fm-btn" onclick="closeAdminModal('deleteLinkModal')">Cancel</button>
        <button type="submit" class="fm-btn fm-btn--danger"><i class="fas fa-trash"></i> Delete</button>
    </div>
</form>
<?php renderAdminModalEnd(); ?>

<!-- ═══ Add Link (with pre-filled column) Modal ══════════════════════════ -->
<?php renderAdminModalStart('addLinkModal', 'Add Footer Link'); ?>
<form method="POST" action="footer-management.php?tab=links">
    <input type="hidden" name="action" value="add_link">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="form-group">
        <label>Column Name <span style="color:#dc3545">*</span></label>
        <input type="text" name="column_name" id="al_col" required list="col-datalist-modal">
    </div>
    <div class="form-group">
        <label>Link Text <span style="color:#dc3545">*</span></label>
        <input type="text" name="link_text" required>
    </div>
    <div class="form-group">
        <label>Link URL (Home page)</label>
        <input type="text" name="link_url" placeholder="e.g. #section or page.php">
    </div>
    <div class="form-group">
        <label>Secondary URL (other pages)</label>
        <input type="text" name="secondary_link_url" placeholder="e.g. page.php">
    </div>
    <div class="form-group">
        <label>Display Order</label>
        <input type="number" name="display_order" value="10" min="0" max="9999">
    </div>
    <div class="modal-actions">
        <button type="button" class="fm-btn" onclick="closeAdminModal('addLinkModal')">Cancel</button>
        <button type="submit" class="fm-btn fm-btn--primary"><i class="fas fa-plus"></i> Add Link</button>
    </div>
</form>
<?php renderAdminModalEnd(); ?>

<!-- ═══ Edit Policy Modal ════════════════════════════════════════════════ -->
<?php renderAdminModalStart('editPolicyModal', 'Edit Policy'); ?>
<form method="POST" action="footer-management.php?tab=policies">
    <input type="hidden" name="action" value="edit_policy">
    <input type="hidden" name="policy_id" id="ep_id">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="form-group">
        <label>Slug (read-only)</label>
        <input type="text" id="ep_slug" disabled style="background:#f3f4f6;cursor:not-allowed;">
    </div>
    <div class="form-group">
        <label>Title <span style="color:#dc3545">*</span></label>
        <input type="text" name="title" id="ep_title" required>
    </div>
    <div class="form-group">
        <label>Summary</label>
        <input type="text" name="summary" id="ep_summary" data-tag-autocomplete>
    </div>
    <div class="form-group">
        <label>Content <span style="color:#dc3545">*</span></label>
        <textarea name="content" id="ep_content" required style="min-height:220px;" data-tag-autocomplete></textarea>
        <p class="fm-tag-hint"><i class="fas fa-magic"></i> Type <code>{{</code> to autocomplete a DB tag — e.g. <code>{{check_in_time}}</code>, <code>{{check_out_time}}</code>, <code>{{site_name}}</code>.</p>
    </div>
    <div class="form-row">
        <div class="form-group">
            <label>Display Order</label>
            <input type="number" name="display_order" id="ep_order" min="0" max="9999">
        </div>
        <div class="form-group" style="display:flex; align-items:center; padding-top:22px; gap:8px;">
            <input type="checkbox" name="is_active" id="ep_active" value="1" style="width:auto;">
            <label for="ep_active" style="margin:0; cursor:pointer;">Active (visible in footer)</label>
        </div>
    </div>
    <div class="modal-actions">
        <button type="button" class="fm-btn" onclick="closeAdminModal('editPolicyModal')">Cancel</button>
        <button type="submit" class="fm-btn fm-btn--primary"><i class="fas fa-save"></i> Save Policy</button>
    </div>
</form>
<?php renderAdminModalEnd(); ?>

<!-- ═══ Delete Policy Modal ══════════════════════════════════════════════ -->
<?php renderAdminModalStart('deletePolicyModal', 'Delete Policy'); ?>
<p>Are you sure you want to delete the policy <strong id="dp_name"></strong>? The footer link that points to its slug will become a dead link.</p>
<form method="POST" action="footer-management.php?tab=policies">
    <input type="hidden" name="action" value="delete_policy">
    <input type="hidden" name="policy_id" id="dp_id">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    <div class="modal-actions">
        <button type="button" class="fm-btn" onclick="closeAdminModal('deletePolicyModal')">Cancel</button>
        <button type="submit" class="fm-btn fm-btn--danger"><i class="fas fa-trash"></i> Delete</button>
    </div>
</form>
<?php renderAdminModalEnd(); ?>

<?php renderAdminModalScript(); ?>

<script>
window._fmAllTags = <?php echo json_encode(array_map(static fn($t) => ['tag' => $t['tag'], 'label' => $t['label'], 'live' => $t['live']], $available_tags), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
</script>

<script>
// ── Link modals ──────────────────────────────────────────────────────────────
function openEditLink(lnk) {
    document.getElementById('el_id').value    = lnk.id;
    document.getElementById('el_col').value   = lnk.column_name;
    document.getElementById('el_text').value  = lnk.link_text;
    document.getElementById('el_url').value   = lnk.link_url || '';
    document.getElementById('el_sec').value   = lnk.secondary_link_url || '';
    document.getElementById('el_order').value = lnk.display_order;
    document.getElementById('el_active').checked = lnk.is_active == 1;
    openAdminModal('editLinkModal');
}
function openDeleteLink(id, name) {
    document.getElementById('dl_id').value     = id;
    document.getElementById('dl_name').textContent = '\u201c' + name + '\u201d';
    openAdminModal('deleteLinkModal');
}
function openAddLink(colName) {
    document.getElementById('al_col').value = colName || '';
    openAdminModal('addLinkModal');
}
bindAdminModal('editLinkModal');
bindAdminModal('deleteLinkModal');
bindAdminModal('addLinkModal');

// ── Policy modals ────────────────────────────────────────────────────────────
function openEditPolicy(pol) {
    document.getElementById('ep_id').value      = pol.id;
    document.getElementById('ep_slug').value    = pol.slug;
    document.getElementById('ep_title').value   = pol.title;
    document.getElementById('ep_summary').value = pol.summary || '';
    document.getElementById('ep_content').value = pol.content || '';
    document.getElementById('ep_order').value   = pol.display_order;
    document.getElementById('ep_active').checked = pol.is_active == 1;
    openAdminModal('editPolicyModal');
}
function openDeletePolicy(id, name) {
    document.getElementById('dp_id').value         = id;
    document.getElementById('dp_name').textContent = '\u201c' + name + '\u201d';
    openAdminModal('deletePolicyModal');
}
bindAdminModal('editPolicyModal');
bindAdminModal('deletePolicyModal');

// ── DB Tag click-to-insert ───────────────────────────────────────────────────
let _fmLastFocused = null;
document.addEventListener('focusin', function (e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') {
        _fmLastFocused = e.target;
    }
});
document.querySelectorAll('.fm-tag-chip').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const tag  = btn.dataset.tag;
        const el   = _fmLastFocused;
        if (!el) {
            btn.title = 'Focus a text field first, then click the tag.';
            return;
        }
        const start = typeof el.selectionStart === 'number' ? el.selectionStart : el.value.length;
        const end   = typeof el.selectionEnd   === 'number' ? el.selectionEnd   : el.value.length;
        el.value = el.value.substring(0, start) + tag + el.value.substring(end);
        el.selectionStart = el.selectionEnd = start + tag.length;
        el.focus();
        el.dispatchEvent(new Event('input', { bubbles: true }));
    });
});

// ── Tag autocomplete engine ──────────────────────────────────────────────────
(function () {
    const ALL_TAGS = window._fmAllTags || [];

    const DROP = document.createElement('div');
    DROP.className = 'fm-autocomplete';
    DROP.setAttribute('role', 'listbox');
    document.body.appendChild(DROP);

    let _field = null, _idx = -1, _matches = [];

    function hide() {
        DROP.style.display = 'none';
        DROP.innerHTML = '';
        _matches = []; _idx = -1;
    }

    function getPartial(el) {
        const before = el.value.substring(0, el.selectionStart);
        const at = before.lastIndexOf('{{');
        if (at === -1) return null;
        const between = before.substring(at + 2);
        if (between.includes('}}')) return null;
        return { partial: between, openAt: at };
    }

    function esc(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function renderDrop(el, partial) {
        const q = partial.toLowerCase();
        _matches = ALL_TAGS.filter(function (t) {
            return t.tag.toLowerCase().includes(q) || t.label.toLowerCase().includes(q);
        }).slice(0, 9);
        if (!_matches.length) { hide(); return; }

        DROP.innerHTML = '';
        _idx = 0;
        _matches.forEach(function (t, i) {
            const d = document.createElement('div');
            d.className = 'fm-autocomplete__item' + (i === 0 ? ' fm-autocomplete__item--active' : '');
            d.setAttribute('role', 'option');
            const live = t.live.length > 28 ? t.live.substring(0, 28) + '\u2026' : t.live;
            d.innerHTML = '<code>' + esc(t.tag) + '</code><span class="fm-autocomplete__label">' + esc(t.label) + '</span><span class="fm-autocomplete__live">' + esc(live) + '</span>';
            d.addEventListener('mousedown', function (e) {
                e.preventDefault();
                doInsert(el, t.tag);
                hide();
            });
            DROP.appendChild(d);
        });

        const r = el.getBoundingClientRect();
        DROP.style.cssText = 'display:block;left:' + (r.left + window.scrollX) + 'px;top:' + (r.bottom + window.scrollY + 3) + 'px;width:' + Math.max(280, r.width) + 'px;';
    }

    function doInsert(el, tag) {
        const info = getPartial(el);
        if (!info) return;
        el.value = el.value.substring(0, info.openAt) + tag + el.value.substring(el.selectionStart);
        const pos = info.openAt + tag.length;
        el.selectionStart = el.selectionEnd = pos;
        el.focus();
        el.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function setActive(i) {
        DROP.querySelectorAll('.fm-autocomplete__item').forEach(function (el, j) {
            el.classList.toggle('fm-autocomplete__item--active', j === i);
        });
        _idx = i;
    }

    document.addEventListener('input', function (e) {
        const el = e.target;
        if (!el.matches('[data-tag-autocomplete]')) return;
        _field = el;
        const info = getPartial(el);
        if (!info) { hide(); return; }
        renderDrop(el, info.partial);
    });

    document.addEventListener('keydown', function (e) {
        if (DROP.style.display === 'none') return;
        if (e.key === 'ArrowDown') { e.preventDefault(); setActive(Math.min(_idx + 1, _matches.length - 1)); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(Math.max(_idx - 1, 0)); }
        else if ((e.key === 'Enter' || e.key === 'Tab') && _idx >= 0 && _matches[_idx]) {
            e.preventDefault();
            doInsert(_field, _matches[_idx].tag);
            hide();
        } else if (e.key === 'Escape') { hide(); }
    });

    document.addEventListener('focusout', function () {
        setTimeout(function () {
            if (DROP.style.display !== 'none' && !DROP.contains(document.activeElement)) hide();
        }, 160);
    });

    document.addEventListener('click', function (e) {
        if (!DROP.contains(e.target)) hide();
    });
}());
</script>

<?php require_once 'includes/admin-footer.php'; ?>

