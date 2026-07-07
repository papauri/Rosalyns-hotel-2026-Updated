<?php

/**
 * Facebook Settings Admin Page
 * Manages Facebook Page API credentials and posting configuration.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

require_once '../includes/facebook-functions.php';

$csrf_token = $csrf_token ?? generateCsrfToken();

// Load current settings
$fb_enabled       = getSetting('facebook_posting_enabled', '0');
$fb_page_id       = getSetting('facebook_page_id', '');
$fb_page_name     = getSetting('facebook_page_name', '');
$fb_hashtags      = getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury');
$fb_rooms_enabled      = getSetting('facebook_rooms_enabled', '1');
$fb_events_enabled     = getSetting('facebook_events_enabled', '1');
$fb_conference_enabled = getSetting('facebook_conference_enabled', '1');
$fb_menu_enabled       = getSetting('facebook_menu_enabled', '1');
$fb_log_enabled        = getSetting('facebook_post_log_enabled', '1');
// Token: never pre-fill plaintext in the form — show masked indicator only
$fb_has_token     = (string) getSetting('facebook_page_access_token', '') !== '';

$message    = '';
$error      = '';

// ── Save settings ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $newToken = trim($_POST['facebook_page_access_token'] ?? '');

        $settings = [
            'facebook_posting_enabled'   => isset($_POST['facebook_posting_enabled']) ? '1' : '0',
            'facebook_page_id'           => trim($_POST['facebook_page_id'] ?? ''),
            'facebook_page_name'         => trim($_POST['facebook_page_name'] ?? ''),
            'facebook_default_hashtags'  => trim($_POST['facebook_default_hashtags'] ?? ''),
            'facebook_rooms_enabled'      => isset($_POST['facebook_rooms_enabled']) ? '1' : '0',
            'facebook_events_enabled'     => isset($_POST['facebook_events_enabled']) ? '1' : '0',
            'facebook_conference_enabled' => isset($_POST['facebook_conference_enabled']) ? '1' : '0',
            'facebook_menu_enabled'       => isset($_POST['facebook_menu_enabled']) ? '1' : '0',
            'facebook_post_log_enabled'   => isset($_POST['facebook_post_log_enabled']) ? '1' : '0',
        ];

        // Only overwrite the token if a new one was submitted — store encrypted
        if ($newToken !== '') {
            $settings['facebook_page_access_token'] = function_exists('encryptApiKey') ? encryptApiKey($newToken) : $newToken;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            INSERT INTO site_settings (setting_key, setting_value)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");

        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value]);
        }

        $pdo->commit();

        // Refresh cached values for the current page render
        $fb_enabled        = $settings['facebook_posting_enabled'];
        $fb_page_id        = $settings['facebook_page_id'];
        $fb_page_name      = $settings['facebook_page_name'];
        $fb_hashtags       = $settings['facebook_default_hashtags'];
        $fb_rooms_enabled      = $settings['facebook_rooms_enabled'];
        $fb_events_enabled     = $settings['facebook_events_enabled'];
        $fb_conference_enabled = $settings['facebook_conference_enabled'];
        $fb_menu_enabled       = $settings['facebook_menu_enabled'];
        $fb_log_enabled        = $settings['facebook_post_log_enabled'];
        if ($newToken !== '') {
            $fb_has_token = true;
        }

        if (function_exists('clearSettingsCache')) {
            clearSettingsCache();
        }

        rh_log_event('facebook_settings', 'info', 'Facebook settings saved', [
            'enabled'        => $fb_enabled,
            'has_page_id'    => $fb_page_id !== '',
            'has_token'      => $fb_has_token,
            'saved_by'       => $user['username'] ?? 'unknown',
        ]);

        $message = 'Facebook settings saved successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error saving settings: ' . $e->getMessage();
        rh_log_event('facebook_settings', 'error', 'Failed saving Facebook settings', ['error' => $e->getMessage()]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Facebook Settings — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <link rel="stylesheet" href="css/admin-booking-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-booking-settings.css'); ?>">
    <link rel="stylesheet" href="css/facebook-settings.css?v=<?php echo @filemtime(__DIR__ . '/css/facebook-settings.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content">
        <a href="booking-settings.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Settings
        </a>

        <div class="page-header">
            <h1 class="page-title">
                <i class="fab fa-facebook-f fb-icon"></i>
                Facebook Settings
            </h1>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <div><?php echo htmlspecialchars($message); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <!-- Main settings form -->
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">

            <!-- API Credentials -->
            <div class="settings-card fb-card">
                <h2><i class="fas fa-key" style="color:var(--color-lux-gold,#B18247);"></i> Page Credentials</h2>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="facebook_posting_enabled" name="facebook_posting_enabled" value="1"
                            <?php echo $fb_enabled === '1' ? 'checked' : ''; ?>>
                        <span style="font-weight:600;">Enable Facebook Page Posting</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        When disabled, all "Post to Facebook" buttons are hidden in the admin panel.
                    </p>
                </div>

                <hr style="margin:25px 0;border-top:2px solid #eee;">

                <div class="form-group">
                    <label for="facebook_page_id"><strong>Facebook Page ID</strong></label>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="text" id="facebook_page_id" name="facebook_page_id" class="form-control"
                            value="<?php echo htmlspecialchars($fb_page_id, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. 106357582370498"
                            autocomplete="off"
                            style="flex:1;min-width:200px;">
                        <button type="button" id="fbFetchPagesBtn" class="btn btn-secondary"
                            style="white-space:nowrap;flex-shrink:0;"
                            <?php echo !$fb_has_token ? 'disabled title="Save a Page Access Token first"' : ''; ?>>
                            <i class="fab fa-facebook-f" style="color:#1877F2;"></i> Fetch from Token
                        </button>
                    </div>
                    <div id="fbFetchResult" style="margin-top:10px;display:none;"></div>
                    <p class="help-text" style="margin-top:6px;">
                        <i class="fas fa-info-circle"></i>
                        If a Page Access Token is saved, click <strong>Fetch from Token</strong> to auto-fill the Page ID and Name.
                    </p>
                </div>

                <div class="form-group">
                    <label for="facebook_page_name"><strong>Page Display Name</strong> <small>(optional label)</small></label>
                    <input type="text" id="facebook_page_name" name="facebook_page_name" class="form-control"
                        value="<?php echo htmlspecialchars($fb_page_name, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="e.g. Rosalyn's Hotel"
                        autocomplete="off">
                    <p class="help-text">Used only as a label in the admin panel — not sent to Facebook.</p>
                </div>

                <div class="form-group">
                    <label for="facebook_page_access_token"><strong>Page Access Token</strong></label>
                    <?php if ($fb_has_token): ?>
                        <p class="help-text" style="margin-bottom:8px;color:#28a745;">
                            <i class="fas fa-lock"></i> A token is currently stored. Paste a new token below to replace it, or leave blank to keep the existing one.
                        </p>
                    <?php endif; ?>
                    <input type="password" id="facebook_page_access_token" name="facebook_page_access_token"
                        class="form-control"
                        autocomplete="new-password"
                        placeholder="<?php echo $fb_has_token ? 'Leave blank to keep existing token' : 'EAAxxxxxxxx...'; ?>">
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        Generate via <strong>Graph API Explorer</strong>: select your Page, add <code>pages_manage_posts</code> + <code>pages_read_engagement</code> + <code>pages_show_list</code> permissions, then exchange for a Page Access Token. Never share this token.
                    </p>
                </div>

                <div class="info-box" style="background:#e7f0ff;border-left-color:#1877F2;">
                    <h4><i class="fas fa-info-circle" style="color:#1877F2;"></i> Token Setup Guide</h4>
                    <ol style="margin:10px 0 0 20px;padding:0;">
                        <li>Go to <a href="https://developers.facebook.com/tools/explorer/" target="_blank" rel="noopener">Graph API Explorer</a></li>
                        <li>Select your App and set User or Page token</li>
                        <li>Add permissions: <code>pages_manage_posts</code>, <code>pages_read_engagement</code>, <code>pages_show_list</code></li>
                        <li>Click <strong>Generate Access Token</strong>, then exchange for a Page token</li>
                        <li>For long-lived tokens, extend via the Access Token Debugger or use a System User token</li>
                    </ol>
                </div>
            </div>

            <!-- Posting options -->
            <div class="settings-card">
                <h2><i class="fas fa-share-alt" style="color:var(--color-lux-gold,#B18247);"></i> Posting Options</h2>

                <div class="form-group">
                    <label for="facebook_default_hashtags"><strong>Default Hashtags</strong></label>
                    <input type="text" id="facebook_default_hashtags" name="facebook_default_hashtags" class="form-control"
                        value="<?php echo htmlspecialchars($fb_hashtags, ENT_QUOTES, 'UTF-8'); ?>"
                        placeholder="#hotel #accommodation #luxury"
                        autocomplete="off">
                    <p class="help-text">Added to the end of every auto-generated post caption. Can be edited per post before sending.</p>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:15px;">
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="facebook_rooms_enabled" value="1"
                                <?php echo $fb_rooms_enabled === '1' ? 'checked' : ''; ?>>
                            <span>Show share button on Rooms</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="facebook_events_enabled" value="1"
                                <?php echo $fb_events_enabled === '1' ? 'checked' : ''; ?>>
                            <span>Show share button on Events</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="facebook_conference_enabled" value="1"
                                <?php echo $fb_conference_enabled === '1' ? 'checked' : ''; ?>>
                            <span>Show share button on Conference rooms</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="facebook_menu_enabled" value="1"
                                <?php echo $fb_menu_enabled === '1' ? 'checked' : ''; ?>>
                            <span>Show share button on Menu items</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                            <input type="checkbox" name="facebook_post_log_enabled" value="1"
                                <?php echo $fb_log_enabled === '1' ? 'checked' : ''; ?>>
                            <span>Log all post attempts to system log</span>
                        </label>
                    </div>
                </div>
            </div>

            <div style="margin-top:24px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Facebook Settings
                </button>
            </div>
        </form>

        <!-- Test post — real-time AJAX -->
        <div class="settings-card" style="margin-top:32px;">
            <h2><i class="fas fa-vial" style="color:var(--color-lux-gold,#B18247);"></i> Test Post</h2>
            <p class="help-text" style="margin-bottom:20px;">
                Send a test post to your Facebook Page to confirm the token and page ID are working correctly.
                This will publish a real post — delete it from your Page afterwards if you don't want it to remain.
            </p>

            <div class="form-group">
                <label for="test_message"><strong>Test message</strong></label>
                <textarea id="test_message" class="form-control" rows="3"
                    placeholder="Test post from hotel admin. Sent at <?php echo date('Y-m-d H:i'); ?>"></textarea>
            </div>

            <button type="button" id="fbTestBtn" class="btn fb-btn" <?php echo !$fb_has_token ? 'disabled title="Save a Page Access Token first"' : ''; ?>>
                <i class="fab fa-facebook-f"></i> Send Test Post to Facebook Page
            </button>

            <!-- Live result area -->
            <div id="fbTestResult" style="margin-top:18px;display:none;border-radius:8px;padding:14px 18px;font-size:0.9rem;line-height:1.5;"></div>
        </div>
    </div>

    <script>
        (function() {
            // ── Fetch Pages from Token ────────────────────────────────────────────────
            var fetchBtn = document.getElementById('fbFetchPagesBtn');
            var fetchResult = document.getElementById('fbFetchResult');
            var pageIdInput = document.getElementById('facebook_page_id');
            var pageNameInput = document.getElementById('facebook_page_name');

            function getCsrf() {
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) return meta.getAttribute('content');
                var inp = document.querySelector('input[name="csrf_token"]');
                return inp ? inp.value : '';
            }

            if (fetchBtn && fetchResult && pageIdInput) {
                fetchBtn.addEventListener('click', function() {
                    fetchBtn.disabled = true;
                    var orig = fetchBtn.innerHTML;
                    fetchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching…';
                    fetchResult.style.display = 'none';
                    fetchResult.innerHTML = '';

                    var fd = new FormData();
                    fd.append('csrf_token', getCsrf());

                    fetch('api/facebook-fetch-pages.php', {
                            method: 'POST',
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            body: fd
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(data) {
                            fetchBtn.disabled = false;
                            fetchBtn.innerHTML = orig;
                            fetchResult.style.display = 'block';

                            if (!data.success) {
                                fetchResult.innerHTML = '<p style="color:#c0392b;margin:0;"><i class="fas fa-exclamation-circle"></i> ' + (data.error || 'Unknown error.') + '</p>';
                                return;
                            }

                            if (!data.pages || data.pages.length === 0) {
                                fetchResult.innerHTML = '<p style="color:#856404;margin:0;"><i class="fas fa-exclamation-triangle"></i> No Pages found for this token. Make sure the token has <code>pages_show_list</code> permission.</p>';
                                return;
                            }

                            // Build page-selector cards
                            var html = '<p style="margin:0 0 8px;font-size:0.85rem;color:#5E554D;">Select a Page to fill the fields:</p>';
                            html += '<div style="display:flex;flex-wrap:wrap;gap:8px;">';
                            data.pages.forEach(function(p) {
                                html += '<button type="button" class="btn btn-secondary fb-page-pick" style="font-size:0.85rem;"' +
                                    ' data-id="' + p.id.replace(/"/g, '') + '"' +
                                    ' data-name="' + p.name.replace(/"/g, '&quot;') + '">' +
                                    '<i class="fab fa-facebook-f" style="color:#1877F2;margin-right:5px;"></i>' +
                                    '<strong>' + p.name + '</strong>' +
                                    ' <small style="opacity:0.7;">(' + p.id + ')</small>' +
                                    '</button>';
                            });
                            html += '</div>';
                            fetchResult.innerHTML = html;

                            // Wire pick buttons
                            fetchResult.querySelectorAll('.fb-page-pick').forEach(function(btn) {
                                btn.addEventListener('click', function() {
                                    pageIdInput.value = this.dataset.id;
                                    if (pageNameInput && this.dataset.name) {
                                        pageNameInput.value = this.dataset.name;
                                    }
                                    fetchResult.innerHTML = '<p style="color:#1a8a3e;margin:0;"><i class="fas fa-check-circle"></i> Page ID and Name filled. Click <strong>Save Facebook Settings</strong> to store.</p>';
                                });
                            });
                        })
                        .catch(function() {
                            fetchBtn.disabled = false;
                            fetchBtn.innerHTML = orig;
                            fetchResult.style.display = 'block';
                            fetchResult.innerHTML = '<p style="color:#c0392b;margin:0;"><i class="fas fa-wifi"></i> Network error. Try again.</p>';
                        });
                });
            }

            // ── Test Post ─────────────────────────────────────────────────────────────
            var btn = document.getElementById('fbTestBtn');
            var result = document.getElementById('fbTestResult');
            var msgArea = document.getElementById('test_message');
            if (!btn || !result) return;

            btn.addEventListener('click', function() {
                var msg = (msgArea ? msgArea.value.trim() : '') || '';

                // Spinner state
                btn.disabled = true;
                var origHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting to Facebook…';
                result.style.display = 'none';
                result.innerHTML = '';

                var csrfToken = document.querySelector('meta[name="csrf-token"]') ?
                    document.querySelector('meta[name="csrf-token"]').getAttribute('content') :
                    (document.querySelector('input[name="csrf_token"]') ?
                        document.querySelector('input[name="csrf_token"]').value : '');

                var fd = new FormData();
                fd.append('csrf_token', csrfToken);
                fd.append('message', msg);

                fetch('api/facebook-test-post.php', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: fd
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                        result.style.display = 'block';

                        if (data.success) {
                            var viewLink = data.post_url ?
                                ' &mdash; <a href="' + data.post_url + '" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;">View post on Facebook</a>' :
                                '';
                            var postIdLine = data.post_id ?
                                '<br><small style="opacity:0.85;">Post ID: ' + data.post_id + '</small>' :
                                '';
                            result.style.background = '#1a8a3e';
                            result.style.color = '#fff';
                            result.innerHTML =
                                '<i class="fas fa-check-circle" style="margin-right:8px;font-size:1.1em;"></i>' +
                                '<strong>Success!</strong> ' + data.message + viewLink + postIdLine;
                        } else {
                            result.style.background = '#c0392b';
                            result.style.color = '#fff';
                            result.innerHTML =
                                '<i class="fas fa-exclamation-circle" style="margin-right:8px;font-size:1.1em;"></i>' +
                                '<strong>Failed.</strong> ' + (data.error || 'Unknown error.');
                        }
                    })
                    .catch(function() {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                        result.style.display = 'block';
                        result.style.background = '#c0392b';
                        result.style.color = '#fff';
                        result.innerHTML =
                            '<i class="fas fa-wifi" style="margin-right:8px;"></i>' +
                            '<strong>Network error.</strong> Check your connection and try again.';
                    });
            });
        }());
    </script>

<?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>

