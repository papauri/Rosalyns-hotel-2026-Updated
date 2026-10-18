<?php

/**
 * Facebook Settings Admin Page
 *
 * One status card (Connected as <Page>, token expiry, Reconnect / Test connection / Disconnect)
 * plus the credentials form. "Connect with Facebook" runs the OAuth login:
 *   dialog -> ?code -> long-lived user token -> /me/accounts -> Page access token (stored encrypted).
 * The callback is this same page (see facebookRedirectUri()), protected by a one-time state
 * kept in the session. A hand-pasted Page token is still possible under Advanced.
 */

require_once 'admin-init.php';
/** @var array $user */
/** @var string $csrf_token */

require_once '../includes/facebook-functions.php';

$csrf_token = $csrf_token ?? generateCsrfToken();

$redirectUri = facebookRedirectUri();
$connectedBy = (string) ($user['username'] ?? '');

/** Queue a message for the next render and reload the page (so a refresh never repeats an action). */
$fbFlashRedirect = static function (string $type, string $msg): void {
    $_SESSION['fb_flash'] = ['type' => $type, 'msg' => $msg];
    header('Location: facebook-settings.php');
    exit;
};

/** Store a chosen Page (token + expiry from Facebook) and run a read-only check. Returns [type, message]. */
$fbStorePage = static function (string $pageId, string $pageName, string $pageToken, bool $extended) use ($connectedBy): array {
    $dbg = facebookDebugToken($pageToken);
    $expires = $dbg['ok'] ? (int) $dbg['expires_at'] : -1; // 0 = never expires, -1 = unknown
    facebookStoreConnection($pageId, $pageName, $pageToken, $expires, $connectedBy);
    rh_log_event('facebook_settings', 'info', 'Facebook connected', ['page_id' => $pageId, 'by' => $connectedBy]);
    $check = facebookCheckConnection();
    if (!$check['connected']) {
        return ['error', 'Saved the Page, but the connection check failed. ' . $check['error']];
    }
    $note = '';
    if (!$extended) {
        $note = ' The token could not be extended, so it may stop working within a few hours; click Reconnect again later.';
    } elseif ($check['warning'] !== '') {
        $note = ' ' . $check['warning'];
    }
    return [$note === '' ? 'success' : 'warning', 'Connected to ' . ($check['page_name'] ?: $pageName) . '.' . $note];
};

// ── OAuth callback (GET ?code=…&state=… or ?error=…) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['code']) || isset($_GET['error']))) {
    if (!facebookConsumeOauthState((string) ($_GET['state'] ?? ''))) {
        $fbFlashRedirect('error', 'The Facebook sign-in could not be verified (it expired or was not started here). Click Connect with Facebook again.');
    }
    if (isset($_GET['error'])) {
        $why = trim((string) ($_GET['error_description'] ?? $_GET['error_reason'] ?? $_GET['error']));
        $fbFlashRedirect('error', 'Facebook did not complete the sign-in: ' . mb_substr($why, 0, 300));
    }

    $ex = facebookExchangeCodeForLongLivedToken((string) $_GET['code']);
    if (!$ex['ok']) {
        rh_log_event('facebook_settings', 'warning', 'Facebook token exchange failed', ['error' => mb_substr($ex['error'], 0, 300)]);
        $fbFlashRedirect('error', $ex['error']);
    }
    $list = facebookListManagedPages($ex['token']);
    if (!$list['ok']) {
        $fbFlashRedirect('error', 'Signed in, but the Page list could not be read. ' . $list['error']);
    }
    if (!$list['pages']) {
        $fbFlashRedirect('error', 'Signed in, but Facebook returned no Pages. On the Facebook permission screen choose your Page (or "Opt in to all current and future Pages"), and make sure this Facebook user has full control of the Page.');
    }

    $savedId = (string) getSetting('facebook_page_id', '');
    $chosen  = null;
    foreach ($list['pages'] as $p) {
        if ($savedId !== '' && $p['id'] === $savedId) {
            $chosen = $p;
        }
    }
    if ($chosen === null && count($list['pages']) === 1) {
        $chosen = $list['pages'][0];
    }
    if ($chosen !== null) {
        [$type, $msg] = $fbStorePage($chosen['id'], $chosen['name'], $chosen['access_token'], $ex['extended']);
        $fbFlashRedirect($type, $msg);
    }

    // Several Pages and none matches the saved Page ID: let the admin pick (tokens stay in the session).
    $pending = ['time' => time(), 'user' => (int) ($_SESSION['admin_user_id'] ?? 0), 'extended' => $ex['extended'], 'pages' => []];
    foreach ($list['pages'] as $p) {
        $pending['pages'][$p['id']] = ['name' => $p['name'], 'token' => $p['access_token']];
    }
    $_SESSION['fb_pending'] = $pending;
    header('Location: facebook-settings.php');
    exit;
}

// Pending Page choice (expires after 15 minutes)
$fb_pending = $_SESSION['fb_pending'] ?? null;
if (is_array($fb_pending) && (time() - (int) ($fb_pending['time'] ?? 0) > 900 || (int) ($fb_pending['user'] ?? 0) !== (int) ($_SESSION['admin_user_id'] ?? -1))) {
    unset($_SESSION['fb_pending']);
    $fb_pending = null;
}

$message = '';
$error   = '';
$warning = '';
if (!empty($_SESSION['fb_flash']) && is_array($_SESSION['fb_flash'])) {
    $f = $_SESSION['fb_flash'];
    unset($_SESSION['fb_flash']);
    if (($f['type'] ?? '') === 'success') {
        $message = (string) $f['msg'];
    } elseif (($f['type'] ?? '') === 'warning') {
        $warning = (string) $f['msg'];
    } else {
        $error = (string) ($f['msg'] ?? '');
    }
}

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');
    try {
        if (!isset($_POST['csrf_token']) || !validateCsrfToken($_POST['csrf_token'])) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        if ($action === 'disconnect') {
            facebookClearConnection();
            unset($_SESSION['fb_pending']);
            rh_log_event('facebook_settings', 'info', 'Facebook disconnected', ['by' => $connectedBy]);
            $fbFlashRedirect('success', 'Disconnected. The saved Page token was removed; the App ID and secret were kept so you can reconnect.');
        }

        if ($action === 'choose_page') {
            $pid = (string) ($_POST['page_id'] ?? '');
            if (!is_array($fb_pending) || !isset($fb_pending['pages'][$pid])) {
                throw new RuntimeException('That choice expired. Click Connect with Facebook again.');
            }
            $pg = $fb_pending['pages'][$pid];
            unset($_SESSION['fb_pending']);
            [$type, $msg] = $fbStorePage($pid, (string) $pg['name'], (string) $pg['token'], !empty($fb_pending['extended']));
            $fbFlashRedirect($type, $msg);
        }

        // ── save / connect: validate and store the form ───────────────────────
        $newToken  = trim($_POST['facebook_page_access_token'] ?? '');
        $newSecret = trim($_POST['facebook_app_secret'] ?? '');

        $settings = [
            'facebook_posting_enabled'   => isset($_POST['facebook_posting_enabled']) ? '1' : '0',
            'facebook_page_id'           => trim($_POST['facebook_page_id'] ?? ''),
            'facebook_page_name'         => trim($_POST['facebook_page_name'] ?? ''),
            'facebook_default_hashtags'  => trim($_POST['facebook_default_hashtags'] ?? ''),
            'facebook_rooms_enabled'      => isset($_POST['facebook_rooms_enabled']) ? '1' : '0',
            'facebook_events_enabled'     => isset($_POST['facebook_events_enabled']) ? '1' : '0',
            'facebook_conference_enabled' => isset($_POST['facebook_conference_enabled']) ? '1' : '0',
            'facebook_gym_enabled'        => isset($_POST['facebook_gym_enabled']) ? '1' : '0',
            'facebook_menu_enabled'       => isset($_POST['facebook_menu_enabled']) ? '1' : '0',
            'facebook_post_log_enabled'   => isset($_POST['facebook_post_log_enabled']) ? '1' : '0',
        ];
        // App credentials only when the form carries them (older callers/tests post without).
        if (isset($_POST['facebook_app_id'])) {
            $settings['facebook_app_id'] = trim($_POST['facebook_app_id']);
            $settings['facebook_login_config_id'] = trim($_POST['facebook_login_config_id'] ?? '');
            if ($settings['facebook_app_id'] !== '' && !preg_match('/^[0-9]{5,30}$/', $settings['facebook_app_id'])) {
                throw new RuntimeException('Facebook App ID must be numeric (find it at developers.facebook.com > your app > Settings > Basic).');
            }
            if ($settings['facebook_login_config_id'] !== '' && !preg_match('/^[0-9]{5,30}$/', $settings['facebook_login_config_id'])) {
                throw new RuntimeException('Login configuration ID must be numeric.');
            }
        }
        $hasToken = (string) getSetting('facebook_page_access_token', '') !== '';

        if ($settings['facebook_page_id'] !== '' && !preg_match('/^[0-9]{5,30}$/', $settings['facebook_page_id'])) {
            throw new RuntimeException('Facebook Page ID must be numeric.');
        }
        if (mb_strlen($settings['facebook_page_name']) > 100) {
            throw new RuntimeException('Page name cannot exceed 100 characters.');
        }
        if (mb_strlen($settings['facebook_default_hashtags']) > 300) {
            throw new RuntimeException('Default hashtags cannot exceed 300 characters.');
        }
        if ($settings['facebook_default_hashtags'] !== '' && !preg_match('/^#[^\s#]+(\s+#[^\s#]+)*$/u', $settings['facebook_default_hashtags'])) {
            throw new RuntimeException('Hashtags must each start with # and be separated by spaces, e.g. #hotel #malawi.');
        }
        if ($newToken !== '' && (preg_match('/\s/', $newToken) || mb_strlen($newToken) > 1000)) {
            throw new RuntimeException('The access token looks invalid (no spaces, 1000 characters maximum).');
        }
        if ($newSecret !== '' && (preg_match('/\s/', $newSecret) || mb_strlen($newSecret) > 200)) {
            throw new RuntimeException('The App Secret looks invalid (no spaces, 200 characters maximum).');
        }
        if ($action !== 'connect' && $settings['facebook_posting_enabled'] === '1' && ($settings['facebook_page_id'] === '' || ($newToken === '' && !$hasToken))) {
            throw new RuntimeException('Connect with Facebook (or add a Page ID and token under Advanced) before enabling Facebook posting.');
        }

        // A pasted Page token is checked with Facebook (read-only) before it is stored.
        $manual = null;
        if ($newToken !== '') {
            $me = facebookGraphRequest('GET', '/me', ['fields' => 'id,name'], $newToken);
            if (!$me['ok'] || empty($me['data']['id'])) {
                throw new RuntimeException('Facebook did not accept that token. ' . facebookDescribeError($me));
            }
            $pageToken = $newToken;
            $pgId   = (string) $me['data']['id'];
            $pgName = (string) ($me['data']['name'] ?? '');
            // A user token (not a Page token): swap it for the Page's own token.
            $accounts = facebookListManagedPages($newToken);
            if ($accounts['ok'] && $accounts['pages']) {
                $want = $settings['facebook_page_id'];
                $hit = null;
                foreach ($accounts['pages'] as $p) {
                    if ($want === '' ? count($accounts['pages']) === 1 : $p['id'] === $want) {
                        $hit = $p;
                    }
                }
                if ($hit === null) {
                    throw new RuntimeException('That is a user token and the Page ID does not match one of its Pages. Fill in the Page ID, or use Connect with Facebook.');
                }
                $pageToken = $hit['access_token'];
                $pgId = $hit['id'];
                $pgName = $hit['name'];
            } elseif ($settings['facebook_page_id'] !== '' && $settings['facebook_page_id'] !== $pgId) {
                throw new RuntimeException('That token belongs to "' . $pgName . '" (' . $pgId . '), not to Page ID ' . $settings['facebook_page_id'] . '.');
            }
            $settings['facebook_page_id'] = $pgId;
            if ($settings['facebook_page_name'] === '') {
                $settings['facebook_page_name'] = $pgName;
            }
            $manual = $pageToken;
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
        // Secrets are stored encrypted, and only when a new one was submitted.
        if ($newSecret !== '') {
            $stmt->execute(['facebook_app_secret', facebookEncryptSecret($newSecret)]);
        }
        if ($manual !== null) {
            $stmt->execute(['facebook_page_access_token', facebookEncryptSecret($manual)]);
        }

        $pdo->commit();
        if (function_exists('clearSettingsCache')) {
            clearSettingsCache();
        }
        foreach ($settings as $key => $value) {
            $_SITE_SETTINGS[$key] = $value;
        }

        if ($manual !== null) {
            $dbg = facebookDebugToken($manual);
            facebookSaveSettings([
                'facebook_token_expires_at'  => $dbg['ok'] ? (string) $dbg['expires_at'] : '-1',
                'facebook_connected_at'      => date('Y-m-d H:i:s'),
                'facebook_connected_by'      => $connectedBy,
                'facebook_connection_status' => '',
                'facebook_connection_error'  => '',
            ]);
        }

        rh_log_event('facebook_settings', 'info', 'Facebook settings saved', [
            'enabled'        => $settings['facebook_posting_enabled'],
            'has_page_id'    => $settings['facebook_page_id'] !== '',
            'new_token'      => $manual !== null,
            'saved_by'       => $connectedBy ?: 'unknown',
        ]);

        if ($action === 'connect') {
            [$appId, $secret] = facebookAppCredentials();
            if ($appId === '' || $secret === '') {
                $fbFlashRedirect('error', 'Enter the Facebook App ID and App Secret first (developers.facebook.com > your app > Settings > Basic), then click Connect with Facebook.');
            }
            header('Location: ' . facebookBuildAuthUrl($appId));
            exit;
        }

        $message = 'Facebook settings saved successfully.';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Error saving settings: ' . $e->getMessage();
        rh_log_event('facebook_settings', 'error', 'Failed saving Facebook settings', ['error' => $e->getMessage()]);
    }
}

// ── Load current settings (after any save, so the page shows what is stored) ───
$fb_enabled       = getSetting('facebook_posting_enabled', '0');
$fb_page_id       = getSetting('facebook_page_id', '');
$fb_page_name     = getSetting('facebook_page_name', '');
$fb_hashtags      = getSetting('facebook_default_hashtags', '#hotel #accommodation #luxury');
$fb_rooms_enabled      = getSetting('facebook_rooms_enabled', '1');
$fb_events_enabled     = getSetting('facebook_events_enabled', '1');
$fb_conference_enabled = getSetting('facebook_conference_enabled', '1');
$fb_gym_enabled        = getSetting('facebook_gym_enabled', '1');
$fb_menu_enabled       = getSetting('facebook_menu_enabled', '1');
$fb_log_enabled        = getSetting('facebook_post_log_enabled', '1');
$fb_app_id        = (string) getSetting('facebook_app_id', '');
$fb_has_secret    = (string) getSetting('facebook_app_secret', '') !== '';
$fb_config_id     = (string) getSetting('facebook_login_config_id', '');
// Token: never pre-fill plaintext in the form, only show whether one is stored
$fb_has_token     = (string) getSetting('facebook_page_access_token', '') !== '';
$fb_token_readable = $fb_has_token && facebookStoredToken() !== '';
$fb_status        = (string) getSetting('facebook_connection_status', '');
$fb_status_error  = (string) getSetting('facebook_connection_error', '');
$fb_expires_at    = (string) getSetting('facebook_token_expires_at', '');
$fb_connected_at  = (string) getSetting('facebook_connected_at', '');
$fb_connected_by  = (string) getSetting('facebook_connected_by', '');
$fb_last_checked  = (string) getSetting('facebook_last_checked_at', '');

// Initial state of the status card (the browser then runs a read-only check to refresh it)
if (!$fb_has_token) {
    $fb_state = 'none';
} elseif (!$fb_token_readable || $fb_status === 'error') {
    $fb_state = 'error';
} else {
    $fb_state = 'ok';
}
$h = static function ($v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo $h($csrf_token); ?>">
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
                <div><?php echo $h($message); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($warning): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i>
                <div><?php echo $h($warning); ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <div><?php echo $h($error); ?></div>
            </div>
        <?php endif; ?>

        <?php if (is_array($fb_pending)): ?>
            <!-- Several Pages came back from Facebook: choose which one this hotel posts to -->
            <div class="settings-card fb-card">
                <h2><i class="fab fa-facebook-f" style="color:#1877F2;"></i> Choose the Page to post to</h2>
                <form method="POST" action="" class="fb-actions">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="choose_page">
                    <?php foreach ($fb_pending['pages'] as $pid => $pg): ?>
                        <button type="submit" name="page_id" value="<?php echo $h($pid); ?>" class="btn btn-secondary">
                            <i class="fab fa-facebook-f" style="color:#1877F2;"></i>
                            <strong><?php echo $h($pg['name']); ?></strong> <small>(<?php echo $h($pid); ?>)</small>
                        </button>
                    <?php endforeach; ?>
                </form>
            </div>
        <?php endif; ?>

        <!-- Connection status -->
        <div class="settings-card fb-card" id="fbStatusCard" data-state="<?php echo $h($fb_state); ?>" data-has-token="<?php echo $fb_has_token ? '1' : '0'; ?>">
            <h2><i class="fas fa-plug" style="color:var(--color-lux-gold,#8F6A35);"></i> Connection</h2>

            <div class="fb-status fb-status--<?php echo $h($fb_state); ?>" id="fbStatusBadge">
                <?php if ($fb_state === 'ok'): ?>
                    <i class="fas fa-check-circle"></i>
                    <span id="fbStatusText">Connected as <strong><?php echo $h($fb_page_name !== '' ? $fb_page_name : 'Page ' . $fb_page_id); ?></strong></span>
                <?php elseif ($fb_state === 'error'): ?>
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="fbStatusText"><?php echo $h($fb_token_readable ? 'Connection needs attention' : 'Saved token cannot be read'); ?></span>
                <?php else: ?>
                    <i class="fas fa-unlink"></i>
                    <span id="fbStatusText">Not connected</span>
                <?php endif; ?>
            </div>

            <p class="fb-status-detail" id="fbStatusDetail">
                <?php
                if ($fb_state === 'error') {
                    echo $h($fb_token_readable
                        ? ($fb_status_error !== '' ? $fb_status_error : 'The last check failed.')
                        : 'The server encryption key changed since the token was saved. Click Reconnect to save a fresh one.');
                } elseif ($fb_state === 'none') {
                    echo 'Enter your Facebook App ID and secret below, then click Connect with Facebook and approve the permissions.';
                }
                ?>
            </p>

            <dl class="fb-facts" id="fbFacts"<?php echo $fb_has_token ? '' : ' hidden'; ?>>
                <div><dt>Page ID</dt><dd id="fbFactPage"><?php echo $h($fb_page_id !== '' ? $fb_page_id : '—'); ?></dd></div>
                <div><dt>Token expires</dt><dd id="fbFactExpiry"><?php
                    if ($fb_expires_at === '0') {
                        echo 'Never (Page token)';
                    } elseif ($fb_expires_at !== '' && (int) $fb_expires_at > 0) {
                        echo $h(date('j M Y H:i', (int) $fb_expires_at));
                    } else {
                        echo 'Unknown';
                    }
                ?></dd></div>
                <div><dt>Connected</dt><dd><?php
                    echo $fb_connected_at !== '' ? $h(date('j M Y H:i', strtotime($fb_connected_at)) . ($fb_connected_by !== '' ? ' by ' . $fb_connected_by : '')) : '—';
                ?></dd></div>
                <div><dt>Last checked</dt><dd id="fbFactChecked"><?php echo $fb_last_checked !== '' ? $h(date('j M Y H:i', strtotime($fb_last_checked))) : '—'; ?></dd></div>
            </dl>

            <div class="fb-actions">
                <?php if ($fb_has_token): ?>
                    <button type="button" id="fbCheckBtn" class="btn btn-secondary">
                        <i class="fas fa-stethoscope"></i> Test connection
                    </button>
                    <button type="submit" form="fbMainForm" name="action" value="connect" class="btn fb-btn">
                        <i class="fab fa-facebook-f"></i> Reconnect
                    </button>
                    <form method="POST" action="" style="display:inline;" onsubmit="return confirm('Disconnect from Facebook? Share buttons will be disabled until you reconnect.');">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="action" value="disconnect">
                        <button type="submit" class="btn btn-secondary"><i class="fas fa-unlink"></i> Disconnect</button>
                    </form>
                <?php else: ?>
                    <button type="submit" form="fbMainForm" name="action" value="connect" class="btn fb-btn">
                        <i class="fab fa-facebook-f"></i> Connect with Facebook
                    </button>
                <?php endif; ?>
            </div>
            <p class="help-text" style="margin-top:10px;">
                <i class="fas fa-info-circle"></i>
                Test connection only reads from Facebook; it never posts anything.
            </p>
        </div>

        <!-- Main settings form -->
        <form method="POST" action="" autocomplete="off" id="fbMainForm">
            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
            <!-- First submit button in the form, so pressing Enter in a field saves rather than connects -->
            <button type="submit" name="action" value="save" class="fb-default-submit" tabindex="-1" aria-hidden="true">Save</button>

            <!-- Facebook app -->
            <div class="settings-card fb-card">
                <h2><i class="fas fa-key" style="color:var(--color-lux-gold,#8F6A35);"></i> Facebook App</h2>

                <div class="info-box" style="background:#e7f0ff;border-left-color:#1877F2;">
                    <h4><i class="fas fa-info-circle" style="color:#1877F2;"></i> Add this address to your Facebook app</h4>
                    <p style="margin:6px 0;">In <strong>developers.facebook.com</strong> &gt; your app &gt; <strong>Facebook Login &gt; Settings</strong> &gt; <strong>Valid OAuth Redirect URIs</strong>, add exactly:</p>
                    <p style="margin:6px 0;"><code id="fbRedirectUri" style="user-select:all;word-break:break-all;"><?php echo $h($redirectUri); ?></code></p>
                    <p style="margin:6px 0;font-size:0.85rem;">Keep <em>Client OAuth Login</em> and <em>Web OAuth Login</em> switched on. The app needs the permissions <code>pages_show_list</code>, <code>pages_read_engagement</code> and <code>pages_manage_posts</code>, and the Facebook user who clicks Connect needs full control of the Page.</p>
                </div>

                <div class="form-group">
                    <label for="facebook_app_id"><strong>App ID</strong></label>
                    <input type="text" id="facebook_app_id" name="facebook_app_id" class="form-control"
                        value="<?php echo $h($fb_app_id); ?>" placeholder="e.g. 123456789012345" autocomplete="off" inputmode="numeric">
                </div>

                <div class="form-group">
                    <label for="facebook_app_secret"><strong>App Secret</strong></label>
                    <?php if ($fb_has_secret): ?>
                        <p class="help-text" style="margin-bottom:8px;color:#28a745;">
                            <i class="fas fa-lock"></i> A secret is stored (encrypted). Leave blank to keep it.
                        </p>
                    <?php endif; ?>
                    <input type="password" id="facebook_app_secret" name="facebook_app_secret" class="form-control"
                        autocomplete="new-password" placeholder="<?php echo $fb_has_secret ? 'Leave blank to keep existing secret' : 'App Secret from Settings > Basic'; ?>">
                </div>

                <div class="form-group">
                    <label for="facebook_login_config_id"><strong>Login configuration ID</strong> <small>(only for "Facebook Login for Business" apps)</small></label>
                    <input type="text" id="facebook_login_config_id" name="facebook_login_config_id" class="form-control"
                        value="<?php echo $h($fb_config_id); ?>" placeholder="Leave empty for a normal Facebook Login app" autocomplete="off" inputmode="numeric">
                </div>

                <div class="fb-actions">
                    <button type="submit" name="action" value="connect" class="btn fb-btn">
                        <i class="fab fa-facebook-f"></i> <?php echo $fb_has_token ? 'Save and reconnect' : 'Save and connect with Facebook'; ?>
                    </button>
                </div>
            </div>

            <!-- Page + posting options -->
            <div class="settings-card">
                <h2><i class="fas fa-share-alt" style="color:var(--color-lux-gold,#8F6A35);"></i> Posting</h2>

                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;">
                        <input type="checkbox" id="facebook_posting_enabled" name="facebook_posting_enabled" value="1"
                            <?php echo $fb_enabled === '1' ? 'checked' : ''; ?>>
                        <span style="font-weight:600;">Enable Facebook Page Posting</span>
                    </label>
                    <p class="help-text">
                        <i class="fas fa-info-circle"></i>
                        When off, the share buttons are hidden across the admin panel. When on but not connected, they show greyed out with a hint.
                    </p>
                </div>

                <div class="form-group">
                    <label for="facebook_page_name"><strong>Page Display Name</strong> <small>(label only)</small></label>
                    <input type="text" id="facebook_page_name" name="facebook_page_name" class="form-control"
                        value="<?php echo $h($fb_page_name); ?>"
                        placeholder="e.g. <?php echo $h((string) (getSetting('site_name') ?: 'Hotel name')); ?>"
                        autocomplete="off">
                    <p class="help-text">Filled in automatically when you connect. Not sent to Facebook.</p>
                </div>

                <div class="form-group">
                    <label for="facebook_default_hashtags"><strong>Default Hashtags</strong></label>
                    <input type="text" id="facebook_default_hashtags" name="facebook_default_hashtags" class="form-control"
                        value="<?php echo $h($fb_hashtags); ?>"
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
                            <input type="checkbox" name="facebook_gym_enabled" value="1"
                                <?php echo $fb_gym_enabled === '1' ? 'checked' : ''; ?>>
                            <span>Show share button on Gym packages</span>
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

            <!-- Advanced: manual token + Page ID -->
            <details class="settings-card fb-advanced"<?php echo ($fb_state === 'none' && $fb_page_id !== '') ? ' open' : ''; ?>>
                <summary><i class="fas fa-tools" style="color:var(--color-lux-gold,#8F6A35);"></i> Advanced: paste a token by hand</summary>
                <p class="help-text" style="margin:12px 0;">
                    Only needed if you cannot use Connect with Facebook. Tokens from the Graph API Explorer expire after about an hour, so Connect is strongly recommended. A pasted token is checked with Facebook (read-only) before it is saved.
                </p>

                <div class="form-group">
                    <label for="facebook_page_id"><strong>Facebook Page ID</strong></label>
                    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <input type="text" id="facebook_page_id" name="facebook_page_id" class="form-control"
                            value="<?php echo $h($fb_page_id); ?>"
                            placeholder="e.g. 106357582370498"
                            autocomplete="off"
                            style="flex:1;min-width:200px;">
                        <button type="button" id="fbFetchPagesBtn" class="btn btn-secondary"
                            style="white-space:nowrap;flex-shrink:0;"
                            <?php echo !$fb_has_token ? 'disabled title="Connect first"' : ''; ?>>
                            <i class="fab fa-facebook-f" style="color:#1877F2;"></i> Fetch from token
                        </button>
                    </div>
                    <div id="fbFetchResult" style="margin-top:10px;display:none;"></div>
                </div>

                <div class="form-group">
                    <label for="facebook_page_access_token"><strong>Page Access Token</strong></label>
                    <?php if ($fb_has_token): ?>
                        <p class="help-text" style="margin-bottom:8px;color:#28a745;">
                            <i class="fas fa-lock"></i> A token is stored (encrypted). Paste a new one to replace it, or leave blank to keep it.
                        </p>
                    <?php endif; ?>
                    <input type="password" id="facebook_page_access_token" name="facebook_page_access_token"
                        class="form-control"
                        autocomplete="new-password"
                        placeholder="<?php echo $fb_has_token ? 'Leave blank to keep existing token' : 'EAAxxxxxxxx...'; ?>">
                </div>

                <div class="form-group" style="border-top:1px solid #eee;padding-top:16px;">
                    <label for="test_message"><strong>Send a real test post</strong></label>
                    <textarea id="test_message" class="form-control" rows="2"
                        placeholder="Test post from hotel admin. Sent at <?php echo $h(date('Y-m-d H:i')); ?>"></textarea>
                    <p class="help-text">This publishes a real post on your Page. Delete it from Facebook afterwards if you do not want it to stay.</p>
                    <button type="button" id="fbTestBtn" class="btn btn-secondary" <?php echo !$fb_has_token ? 'disabled title="Connect first"' : ''; ?>>
                        <i class="fab fa-facebook-f"></i> Publish test post
                    </button>
                    <div id="fbTestResult" style="margin-top:14px;display:none;border-radius:8px;padding:12px 16px;font-size:0.9rem;line-height:1.5;"></div>
                </div>
            </details>

            <div style="margin-top:24px;">
                <button type="submit" name="action" value="save" class="btn btn-primary">
                    <i class="fas fa-save"></i> Save Facebook Settings
                </button>
            </div>
        </form>
    </div>

    <script>
        (function() {
            function getCsrf() {
                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) return meta.getAttribute('content');
                var inp = document.querySelector('input[name="csrf_token"]');
                return inp ? inp.value : '';
            }

            function esc(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }

            function post(url, fields) {
                var fd = new FormData();
                fd.append('csrf_token', getCsrf());
                Object.keys(fields || {}).forEach(function(k) {
                    fd.append(k, fields[k]);
                });
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: fd
                }).then(function(r) {
                    return r.text().then(function(t) {
                        try {
                            return JSON.parse(t);
                        } catch (e) {
                            return {
                                success: false,
                                error: 'The server did not return a valid answer (HTTP ' + r.status + ').'
                            };
                        }
                    });
                });
            }

            // ── Status card: read-only connection check ───────────────────────────
            var card = document.getElementById('fbStatusCard');
            var badge = document.getElementById('fbStatusBadge');
            var detail = document.getElementById('fbStatusDetail');
            var checkBtn = document.getElementById('fbCheckBtn');
            var factExpiry = document.getElementById('fbFactExpiry');
            var factChecked = document.getElementById('fbFactChecked');
            var factPage = document.getElementById('fbFactPage');

            function setBadge(state, html) {
                badge.className = 'fb-status fb-status--' + state;
                var icon = state === 'ok' ? 'fa-check-circle' : (state === 'warn' ? 'fa-exclamation-triangle' : (state === 'busy' ? 'fa-spinner fa-spin' : 'fa-exclamation-triangle'));
                badge.innerHTML = '<i class="fas ' + icon + '"></i><span id="fbStatusText">' + html + '</span>';
            }

            function fmtDate(ts) {
                var d = new Date(ts * 1000);
                return d.toLocaleDateString(undefined, {
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                }) + ' ' + d.toLocaleTimeString(undefined, {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function runCheck() {
                if (!card || card.getAttribute('data-has-token') !== '1') return;
                if (checkBtn) checkBtn.disabled = true;
                setBadge('busy', 'Checking with Facebook…');
                post('api/facebook-test-post.php', {
                    mode: 'check'
                }).then(function(d) {
                    if (checkBtn) checkBtn.disabled = false;
                    if (!d.success) {
                        setBadge('error', 'Could not check the connection');
                        detail.textContent = d.error || 'Unknown error.';
                        return;
                    }
                    factChecked.textContent = new Date().toLocaleString();
                    if (d.page_id) factPage.textContent = d.page_id;
                    if (d.never_expires) {
                        factExpiry.textContent = 'Never (Page token)';
                    } else if (d.expires_at > 0) {
                        var days = Math.floor((d.expires_at * 1000 - Date.now()) / 86400000);
                        factExpiry.textContent = fmtDate(d.expires_at) + (days >= 0 ? ' (' + days + ' days left)' : ' (expired)');
                    }
                    if (d.connected) {
                        setBadge(d.warning ? 'warn' : 'ok', 'Connected as <strong>' + esc(d.page_name || d.page_id) + '</strong>');
                        detail.textContent = d.warning || '';
                        if (!d.never_expires && d.expires_at > 0 && d.expires_at * 1000 - Date.now() < 7 * 86400000) {
                            detail.textContent = (detail.textContent ? detail.textContent + ' ' : '') + 'This token expires soon. Click Reconnect to renew it.';
                        }
                    } else {
                        setBadge('error', 'Connection needs attention');
                        detail.textContent = d.error || 'The check failed.';
                    }
                }).catch(function() {
                    if (checkBtn) checkBtn.disabled = false;
                    setBadge('error', 'Network error');
                    detail.textContent = 'Could not reach this server. Check your connection and try again.';
                });
            }
            if (checkBtn) checkBtn.addEventListener('click', runCheck);
            runCheck();

            // ── Fetch Pages from token ────────────────────────────────────────────
            var fetchBtn = document.getElementById('fbFetchPagesBtn');
            var fetchResult = document.getElementById('fbFetchResult');
            var pageIdInput = document.getElementById('facebook_page_id');
            var pageNameInput = document.getElementById('facebook_page_name');

            if (fetchBtn && fetchResult && pageIdInput) {
                fetchBtn.addEventListener('click', function() {
                    fetchBtn.disabled = true;
                    var orig = fetchBtn.innerHTML;
                    fetchBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching…';
                    fetchResult.style.display = 'none';
                    fetchResult.innerHTML = '';

                    post('api/facebook-fetch-pages.php', {}).then(function(data) {
                        fetchBtn.disabled = false;
                        fetchBtn.innerHTML = orig;
                        fetchResult.style.display = 'block';

                        if (!data.success) {
                            fetchResult.innerHTML = '<p style="color:#c0392b;margin:0;"><i class="fas fa-exclamation-circle"></i> ' + esc(data.error || 'Unknown error.') + '</p>';
                            return;
                        }
                        if (!data.pages || data.pages.length === 0) {
                            fetchResult.innerHTML = '<p style="color:#856404;margin:0;"><i class="fas fa-exclamation-triangle"></i> No Pages found for this token. Reconnect and allow <code>pages_show_list</code>.</p>';
                            return;
                        }
                        var html = '<p style="margin:0 0 8px;font-size:0.85rem;color:#5E554D;">Select a Page to fill the fields:</p><div style="display:flex;flex-wrap:wrap;gap:8px;">';
                        data.pages.forEach(function(p) {
                            html += '<button type="button" class="btn btn-secondary fb-page-pick" style="font-size:0.85rem;"' +
                                ' data-id="' + esc(p.id) + '" data-name="' + esc(p.name) + '">' +
                                '<i class="fab fa-facebook-f" style="color:#1877F2;margin-right:5px;"></i>' +
                                '<strong>' + esc(p.name) + '</strong> <small style="opacity:0.7;">(' + esc(p.id) + ')</small></button>';
                        });
                        fetchResult.innerHTML = html + '</div>';
                        fetchResult.querySelectorAll('.fb-page-pick').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                pageIdInput.value = this.dataset.id;
                                if (pageNameInput && this.dataset.name) pageNameInput.value = this.dataset.name;
                                fetchResult.innerHTML = '<p style="color:#1a8a3e;margin:0;"><i class="fas fa-check-circle"></i> Page ID and name filled. Click <strong>Save Facebook Settings</strong> to store.</p>';
                            });
                        });
                    }).catch(function() {
                        fetchBtn.disabled = false;
                        fetchBtn.innerHTML = orig;
                        fetchResult.style.display = 'block';
                        fetchResult.innerHTML = '<p style="color:#c0392b;margin:0;"><i class="fas fa-wifi"></i> Network error. Try again.</p>';
                    });
                });
            }

            // ── Publish a real test post (explicit, confirmed) ────────────────────
            var btn = document.getElementById('fbTestBtn');
            var result = document.getElementById('fbTestResult');
            var msgArea = document.getElementById('test_message');
            if (!btn || !result) return;

            btn.addEventListener('click', function() {
                if (!confirm('This publishes a real post on your Facebook Page. Continue?')) return;
                btn.disabled = true;
                var origHtml = btn.innerHTML;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Posting…';
                result.style.display = 'none';

                post('api/facebook-test-post.php', {
                    mode: 'publish',
                    message: msgArea ? msgArea.value.trim() : ''
                }).then(function(data) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    result.style.display = 'block';
                    if (data.success) {
                        var link = data.post_url ? ' &mdash; <a href="' + esc(data.post_url) + '" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;">View post</a>' : '';
                        result.style.background = '#1a8a3e';
                        result.style.color = '#fff';
                        result.innerHTML = '<strong>Posted.</strong> ' + esc(data.message) + link;
                        runCheck();
                    } else {
                        result.style.background = '#c0392b';
                        result.style.color = '#fff';
                        result.innerHTML = '<strong>Failed.</strong> ' + esc(data.error || 'Unknown error.');
                    }
                }).catch(function() {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                    result.style.display = 'block';
                    result.style.background = '#c0392b';
                    result.style.color = '#fff';
                    result.innerHTML = '<strong>Network error.</strong> Check your connection and try again.';
                });
            });
        }());
    </script>

<?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
