<?php
/**
 * Forgot Password Page
 * Sends a password reset link via email to the admin user
 */

// Include base URL override (if configured) before auto-detection
$override_file = __DIR__ . '/../config/base-url-override.php';
if (file_exists($override_file)) {
    require_once $override_file;
}

// Include base URL configuration for proper redirects
require_once __DIR__ . '/../config/base-url.php';

session_start();

// If already logged in, redirect to dashboard
if (isset($_SESSION['admin_user_id'])) {
    header('Location: dashboard.php');
    exit;
}

require_once '../config/database.php';
require_once __DIR__ . '/../config/security.php';

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua    = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Your session expired. Please try again.';
    } elseif (empty($email)) {
        $error_message = 'Please enter your email address.';
    } elseif (strlen($email) > 254) {
        $error_message = 'That email address is too long.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address, like name@example.com.';
    } else {
        // Rate-limit: max 3 reset requests per IP per 15 minutes
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS admin_activity_log (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NULL,
                username VARCHAR(100) NULL,
                action VARCHAR(50) NOT NULL,
                details TEXT NULL,
                ip_address VARCHAR(45) NULL,
                user_agent VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_action (action),
                INDEX idx_created_at (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (PDOException $e) { /* already exists */ }

        $rl_stmt = $pdo->prepare("
            SELECT COUNT(*) FROM admin_activity_log
            WHERE ip_address = ? AND action = 'password_reset_request'
            AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
        ");
        $rl_stmt->execute([$ip]);
        $recent_resets = (int)$rl_stmt->fetchColumn();

        if ($recent_resets >= 3) {
            $error_message = 'Too many reset requests from this location. Please try again in 15 minutes.';
            $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (username, action, details, ip_address, user_agent) VALUES (?, 'password_reset_blocked', ?, ?, ?)");
            $log_stmt->execute([$email, 'IP rate limit exceeded (' . $recent_resets . ' attempts)', $ip, $ua]);
        } else {
        // Ensure password_resets table exists before doing anything
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS password_resets (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id INT UNSIGNED NOT NULL,
                    token VARCHAR(255) NOT NULL,
                    expires_at DATETIME NOT NULL,
                    used_at DATETIME DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_token (token),
                    INDEX idx_user_id (user_id),
                    CONSTRAINT fk_password_resets_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            // Table likely already exists
        }

        try {
            // Look up user by email
            $stmt = $pdo->prepare("SELECT id, username, email, full_name, is_active FROM admin_users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && $user['is_active']) {
                // Generate a secure token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

                // Store token in database
                $stmt = $pdo->prepare("
                    INSERT INTO password_resets (user_id, token, expires_at, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$user['id'], hash('sha256', $token), $expires]);

                // Log this reset attempt so rate-limiting can count it
                $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (username, action, details, ip_address, user_agent) VALUES (?, 'password_reset_request', ?, ?, ?)");
                $log_stmt->execute([$user['username'], 'Reset token issued', $ip, $ua]);

                // Build reset URL
                $site_url = getSetting('site_url', '');
                if (empty($site_url)) {
                    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $site_url = $protocol . '://' . $_SERVER['HTTP_HOST'];
                }
                $reset_url = rtrim($site_url, '/') . '/admin/reset-password.php?token=' . $token;

                // Send reset email
                require_once '../config/email.php';

                $site_name = getSetting('site_name', 'Hotel');

                $htmlBody = '
                <!DOCTYPE html>
                <html>
                <head><meta charset="UTF-8"></head>
                <body style="margin: 0; padding: 0; background: #f5f5f5; font-family: Arial, sans-serif;">
                    <div style="max-width: 600px; margin: 40px auto; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);">
                        <div style="background: linear-gradient(135deg, #1A1A1A 0%, #252525 100%); padding: 40px 30px; text-align: center;">
                            <h1 style="color: #7E684B; font-size: 24px; margin: 0 0 8px;">Password Reset</h1>
                            <p style="color: rgba(255,255,255,0.7); font-size: 14px; margin: 0;">' . htmlspecialchars($site_name) . '</p>
                        </div>
                        <div style="padding: 40px 30px;">
                            <p style="color: #333; font-size: 15px; line-height: 1.6;">Hi <strong>' . htmlspecialchars($user['full_name']) . '</strong>,</p>
                            <p style="color: #555; font-size: 14px; line-height: 1.6;">We received a request to reset the password for your admin account (<strong>' . htmlspecialchars($user['username']) . '</strong>).</p>
                            <p style="color: #555; font-size: 14px; line-height: 1.6;">Click the button below to create a new password. This link expires in <strong>1 hour</strong>.</p>
                            <div style="text-align: center; margin: 32px 0;">
                                <a href="' . htmlspecialchars($reset_url) . '" style="display: inline-block; background: linear-gradient(135deg, #7E684B 0%, #c49b2e 100%); color: #050D14; padding: 14px 40px; border-radius: 10px; text-decoration: none; font-weight: 700; font-size: 15px; letter-spacing: 0.5px;">
                                    Reset Password
                                </a>
                            </div>
                            <p style="color: #999; font-size: 12px; line-height: 1.6;">If you didn\'t request this, please ignore this email. Your password will remain unchanged.</p>
                            <hr style="border: none; border-top: 1px solid #eee; margin: 24px 0;">
                            <p style="color: #bbb; font-size: 11px; text-align: center;">This is an automated email from ' . htmlspecialchars($site_name) . ' Admin Panel</p>
                        </div>
                    </div>
                </body>
                </html>';

                $result = sendEmail(
                    $user['email'],
                    $user['full_name'],
                    'Password Reset - ' . $site_name . ' Admin',
                    $htmlBody
                );

                if ($result['success']) {
                    header('Location: login.php?reset=sent');
                    exit;
                } else {
                    error_log("Password reset email failed: " . $result['message']);
                    // The mail never left: retire the token and tell the user honestly.
                    $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE token = ?")->execute([hash('sha256', $token)]);
                    $error_message = 'We could not send the reset email right now. Please try again in a few minutes or contact your administrator.';
                }
            } else {
                // Count every lookup toward the rate limit, found or not, so this
                // form cannot be used to probe for registered addresses.
                $detail = $user ? 'Account deactivated' : 'Unknown email';
                $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'password_reset_request', ?, ?, ?)");
                $log_stmt->execute([$user['id'] ?? null, $email, $detail, $ip, $ua]);

                $error_message = $user
                    ? 'This account has been deactivated. Contact your administrator.'
                    : 'No admin account is registered with that email address. Check the spelling or contact your administrator.';
            }
        } catch (PDOException $e) {
            error_log("Password reset error: " . $e->getMessage());
            $error_message = 'An error occurred. Please try again.';
        }
        } // end rate-limit else
    } // end valid-email else
}

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password | <?php echo htmlspecialchars($site_name); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="stylesheet" href="css/admin-login.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-login.css'); ?>">
</head>
<body>
    <div class="login-shell">
        <aside class="login-hero" aria-hidden="true">
            <img class="login-hero__img" src="../images/hero/slide1.jpeg" alt="" decoding="async" fetchpriority="high">
            <div class="login-hero__brand">
                <span class="login-hero__mark"><i class="fas fa-hotel"></i></span>
                <span class="login-hero__name"><?php echo htmlspecialchars($site_name); ?></span>
            </div>
            <div class="login-hero__copy">
                <p class="login-hero__eyebrow">Staff Portal</p>
                <h2 class="login-hero__title">Hospitality, <em>run with grace.</em></h2>
                <p class="login-hero__lede">Reservations, rooms, dining and guests, all in one calm and considered place.</p>
            </div>
        </aside>

        <main class="login-panel">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-key"></i>
                </div>
                <h1>Forgot Password</h1>
                <p>Enter the email address associated with your admin account and we'll send you a reset link.</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert-danger" role="alert">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="forgotForm" novalidate>
                <?php echo getCsrfField(); ?>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope"></i>
                        <input type="email" id="email" name="email" class="form-control<?php echo $error_message ? ' is-invalid' : ''; ?>"
                               placeholder="Enter your email" required autofocus maxlength="254"
                               autocomplete="email" inputmode="email" autocapitalize="none" spellcheck="false"
                               aria-describedby="email-error"
                               value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                    </div>
                    <span class="field-error" id="email-error" aria-live="polite"></span>
                </div>

                <button type="submit" class="btn-login" id="forgotBtn">
                    <span class="btn-text"><i class="fas fa-paper-plane"></i> Send Reset Link</span>
                    <span class="btn-spinner"></span>
                </button>
            </form>

            <div class="login-footer">
                <a href="login.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        </div>
        </main>
    </div>
    <script>
        (function () {
            'use strict';
            var form = document.getElementById('forgotForm');
            var emailEl = document.getElementById('email');
            var errEl = document.getElementById('email-error');
            var btn = document.getElementById('forgotBtn');
            // Pragmatic check; the server re-validates with FILTER_VALIDATE_EMAIL.
            var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

            function setError(msg) {
                emailEl.classList.add('is-invalid');
                emailEl.classList.remove('is-valid');
                emailEl.setAttribute('aria-invalid', 'true');
                errEl.textContent = msg;
            }
            function clearError() {
                emailEl.classList.remove('is-invalid');
                emailEl.removeAttribute('aria-invalid');
                errEl.textContent = '';
            }
            function validate() {
                var v = emailEl.value.trim();
                if (!v) { setError('Email address is required.'); return false; }
                if (v.length > 254) { setError('That email address is too long.'); return false; }
                if (v.indexOf('@') === -1) { setError('Include an "@" in the email address.'); return false; }
                if (!EMAIL_RE.test(v)) { setError('Enter a valid email address, like name@example.com.'); return false; }
                clearError();
                emailEl.classList.add('is-valid');
                return true;
            }

            emailEl.addEventListener('blur', function () { if (emailEl.value.length) validate(); });
            emailEl.addEventListener('input', function () {
                emailEl.classList.remove('is-valid');
                if (emailEl.classList.contains('is-invalid')) clearError();
            });
            form.addEventListener('submit', function (e) {
                emailEl.value = emailEl.value.trim();
                if (!validate()) { e.preventDefault(); emailEl.focus(); return; }
                btn.disabled = true;
                btn.classList.add('loading');
            });
            // Re-enable if the user comes back via the browser's back button.
            window.addEventListener('pageshow', function (ev) {
                if (ev.persisted) { btn.disabled = false; btn.classList.remove('loading'); }
            });
        })();
    </script>
</body>
</html>

