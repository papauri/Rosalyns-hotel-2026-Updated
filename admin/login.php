<?php

/**
 * Admin Login Page
 * Simple session-based authentication
 */

// Include base URL override (if configured) before auto-detection
$override_file = __DIR__ . '/../config/base-url-override.php';
if (file_exists($override_file)) {
    require_once $override_file;
}

// Include base URL configuration for proper redirects
require_once __DIR__ . '/../config/base-url.php';

// Start session
session_start();

function admin_sanitize_redirect(?string $rawRedirect): string
{
    $redirect = trim((string)$rawRedirect);
    if ($redirect === '') {
        return '';
    }

    $redirect = str_replace(["\r", "\n"], '', $redirect);
    $decoded = trim(rawurldecode($redirect));
    if ($decoded === '') {
        return '';
    }

    // Block absolute URLs / protocol-relative redirects.
    if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $decoded) === 1 || str_starts_with($decoded, '//') || str_starts_with($decoded, '\\\\')) {
        return '';
    }

    // Keep redirects inside admin pages only.
    $decoded = ltrim($decoded, '/');
    if (str_starts_with($decoded, 'admin/')) {
        $decoded = substr($decoded, 6);
    }

    if ($decoded === '' || str_starts_with(strtolower($decoded), 'login.php') || str_contains($decoded, '..')) {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9._\-\/\?&=%#]+$/', $decoded) !== 1) {
        return '';
    }

    return $decoded;
}

function admin_default_route_for_role(string $role): string
{
    if ($role === 'restaurant_staff') {
        return 'pos.php';
    }
    if ($role === 'chef') {
        return 'kds.php';
    }
    if ($role === 'bar_staff') {
        return 'bds.php';
    }
    if ($role === 'coffee_staff') {
        return 'cds.php';
    }
    if ($role === 'room_service') {
        return 'room-service-dashboard.php';
    }
    return 'dashboard.php';
}

$requested_redirect = admin_sanitize_redirect(
    $_POST['redirect'] ?? $_GET['redirect'] ?? ($_SESSION['admin_redirect_after_login'] ?? '')
);
if ($requested_redirect !== '') {
    $_SESSION['admin_redirect_after_login'] = $requested_redirect;
}

// Check if already logged in
if (isset($_SESSION['admin_user_id'])) {
    $sessionRedirect = admin_sanitize_redirect($_SESSION['admin_redirect_after_login'] ?? '');
    if ($sessionRedirect !== '') {
        unset($_SESSION['admin_redirect_after_login']);
        header('Location: ' . $sessionRedirect);
        exit;
    }

    header('Location: ' . admin_default_route_for_role((string)($_SESSION['admin_role'] ?? '')));
    exit;
}

require_once '../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../includes/system-logger.php';

$error_message = '';

// Ensure admin_activity_log table exists
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
        INDEX idx_user_id (user_id),
        INDEX idx_action (action),
        INDEX idx_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (PDOException $e) {
    // Table likely already exists
}

// Max failed attempts before temporary lockout
$max_attempts = 5;
$lockout_minutes = 15;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Your session expired. Please try signing in again.';
    } elseif ($username && $password) {
        try {
            // Check for IP-based rate limiting (too many failed attempts from this IP)
            $rate_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM admin_activity_log
                WHERE ip_address = ? AND action = 'login_failed'
                AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)
            ");
            $rate_stmt->execute([$ip, $lockout_minutes]);
            $recent_ip_failures = $rate_stmt->fetchColumn();

            if ($recent_ip_failures >= ($max_attempts * 2)) {
                $error_message = 'Too many login attempts from this location. Please try again in ' . $lockout_minutes . ' minutes.';

                // Log the blocked attempt
                $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (username, action, details, ip_address, user_agent) VALUES (?, 'login_blocked', ?, ?, ?)");
                $log_stmt->execute([$username, 'IP rate limit exceeded (' . $recent_ip_failures . ' attempts)', $ip, $ua]);
                rh_log_event('auth', 'warning', 'Login blocked — IP rate limit', ['username' => $username, 'ip' => $ip, 'attempts' => $recent_ip_failures]);
            } else {
                $stmt = $pdo->prepare("SELECT id, username, password_hash, role, full_name, email, failed_login_attempts, is_active FROM admin_users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($user && !$user['is_active']) {
                    $error_message = 'This account has been deactivated. Contact your administrator.';

                    $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'login_failed', ?, ?, ?)");
                    $log_stmt->execute([$user['id'], $username, 'Account deactivated', $ip, $ua]);
                    rh_log_event('auth', 'warning', 'Login rejected — account deactivated', ['username' => $username, 'user_id' => $user['id'], 'ip' => $ip]);
                } elseif ($user && $user['failed_login_attempts'] >= $max_attempts) {
                    // Check if lockout period has passed by looking at last failed attempt
                    $last_fail = $pdo->prepare("
                        SELECT created_at FROM admin_activity_log
                        WHERE user_id = ? AND action = 'login_failed'
                        ORDER BY created_at DESC LIMIT 1
                    ");
                    $last_fail->execute([$user['id']]);
                    $last_fail_time = $last_fail->fetchColumn();

                    if ($last_fail_time && strtotime($last_fail_time) > strtotime("-{$lockout_minutes} minutes")) {
                        $remaining = $lockout_minutes - floor((time() - strtotime($last_fail_time)) / 60);
                        $error_message = 'Account temporarily locked due to too many failed attempts. Try again in ' . max(1, $remaining) . ' minute(s).';

                        $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'login_blocked', ?, ?, ?)");
                        $log_stmt->execute([$user['id'], $username, 'Account locked (' . $user['failed_login_attempts'] . ' failed attempts)', $ip, $ua]);
                        rh_log_event('auth', 'warning', 'Login blocked — account locked out', ['username' => $username, 'user_id' => $user['id'], 'ip' => $ip, 'failed_attempts' => $user['failed_login_attempts']]);
                    } else {
                        // Lockout expired, reset counter and allow attempt
                        $pdo->prepare("UPDATE admin_users SET failed_login_attempts = 0 WHERE id = ?")->execute([$user['id']]);
                        $user['failed_login_attempts'] = 0;
                        // Fall through to normal verification below
                        goto verify_password;
                    }
                } else {
                    verify_password:
                    if ($user && password_verify($password, $user['password_hash'])) {
                        // Successful login
                        $_SESSION['admin_user_id'] = $user['id'];
                        $_SESSION['admin_username'] = $user['username'];
                        $_SESSION['admin_role'] = $user['role'];
                        $_SESSION['admin_full_name'] = $user['full_name'];

                        $_SESSION['admin_user'] = [
                            'id' => $user['id'],
                            'username' => $user['username'],
                            'role' => $user['role'],
                            'full_name' => $user['full_name']
                        ];

                        // Reset failed attempts and update last_login
                        $pdo->prepare("UPDATE admin_users SET failed_login_attempts = 0, last_login = NOW() WHERE id = ?")->execute([$user['id']]);

                        // Log successful login
                        $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'login_success', ?, ?, ?)");
                        $log_stmt->execute([$user['id'], $user['username'], 'Role: ' . $user['role'], $ip, $ua]);
                        rh_log_event('auth', 'info', 'Admin login successful', ['username' => $user['username'], 'role' => $user['role'], 'ip' => $ip]);

                        $postLoginRedirect = admin_sanitize_redirect($_POST['redirect'] ?? ($_SESSION['admin_redirect_after_login'] ?? ''));
                        if ($postLoginRedirect !== '') {
                            unset($_SESSION['admin_redirect_after_login']);
                            header('Location: ' . $postLoginRedirect);
                            exit;
                        }

                        unset($_SESSION['admin_redirect_after_login']);
                        header('Location: ' . admin_default_route_for_role((string)$user['role']));
                        exit;
                    } else {
                        // Failed login
                        $attempts = 0;
                        if ($user) {
                            $attempts = $user['failed_login_attempts'] + 1;
                            $pdo->prepare("UPDATE admin_users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);

                            $remaining = $max_attempts - $attempts;
                            $detail = 'Wrong password (attempt ' . $attempts . '/' . $max_attempts . ')';

                            $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (user_id, username, action, details, ip_address, user_agent) VALUES (?, ?, 'login_failed', ?, ?, ?)");
                            $log_stmt->execute([$user['id'], $username, $detail, $ip, $ua]);
                            rh_log_event('auth', 'warning', 'Admin login failed — wrong password', ['username' => $username, 'user_id' => $user['id'], 'attempt' => $attempts, 'ip' => $ip]);

                            if ($remaining > 0 && $remaining <= 2) {
                                $error_message = 'Invalid username or password. ' . $remaining . ' attempt(s) remaining before lockout.';
                            } elseif ($remaining <= 0) {
                                $error_message = 'Account locked for ' . $lockout_minutes . ' minutes due to too many failed attempts.';
                            } else {
                                $error_message = 'Invalid username or password.';
                            }
                        } else {
                            // Unknown username
                            $log_stmt = $pdo->prepare("INSERT INTO admin_activity_log (username, action, details, ip_address, user_agent) VALUES (?, 'login_failed', 'Unknown username', ?, ?)");
                            $log_stmt->execute([$username, $ip, $ua]);
                            rh_log_event('auth', 'warning', 'Admin login failed — unknown username', ['username' => $username, 'ip' => $ip]);

                            $error_message = 'Invalid username or password.';
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $error_message = 'Login error. Please try again.';
        }
    } else {
        $error_message = 'Please enter both username and password.';
    }
}

$site_name = getSetting('site_name');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login | <?php echo htmlspecialchars($site_name); ?></title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <link rel="manifest" href="manifest.php">
    <!-- Keep login page lean: do not load full frontend bundle to avoid duplicate imports -->
    <link rel="stylesheet" href="css/admin-auth.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-auth.css'); ?>">
</head>

<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo">
                    <i class="fas fa-hotel"></i>
                </div>
                <h1>Admin Portal</h1>
                <p><?php echo htmlspecialchars($site_name); ?></p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['reset']) && $_GET['reset'] === 'sent'): ?>
                <div class="alert-success">
                    Password reset link sent to your email.
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['reset']) && $_GET['reset'] === 'success'): ?>
                <div class="alert-success">
                    Password reset successfully. Please log in.
                </div>
            <?php endif; ?>

            <form method="POST" action="login.php" id="loginForm" novalidate>
                <?php echo getCsrfField(); ?>
                <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($requested_redirect, ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <i class="fas fa-user"></i>
                        <input type="text" id="username" name="username" class="form-control"
                            placeholder="Enter your username" autofocus autocomplete="username"
                            value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
                    </div>
                    <span class="field-error" id="username-error" aria-live="polite"></span>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper has-toggle">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="password" name="password" class="form-control"
                            placeholder="Enter your password" autocomplete="current-password">
                        <button type="button" class="password-toggle" id="toggleBtn"
                            aria-label="Show password" title="Show/hide password">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                    <span class="field-error" id="password-error" aria-live="polite"></span>
                </div>

                <button type="submit" class="btn-login" id="loginBtn">
                    <span class="btn-text"><i class="fas fa-sign-in-alt"></i> Sign In</span>
                    <span class="btn-spinner"></span>
                </button>
            </form>

            <div class="login-footer">
                <a href="forgot-password.php">
                    <i class="fas fa-key"></i> Forgot Password?
                </a>
                <a href="../index.php">
                    <i class="fas fa-arrow-left"></i> Back to Website
                </a>
            </div>
        </div>
    </div>

    <script>
        (function() {
            'use strict';

            const form = document.getElementById('loginForm');
            const usernameEl = document.getElementById('username');
            const passwordEl = document.getElementById('password');
            const toggleBtn = document.getElementById('toggleBtn');
            const toggleIcon = document.getElementById('toggleIcon');
            const loginBtn = document.getElementById('loginBtn');

            /* ── Toggle password visibility ─────────────────────────────── */
            toggleBtn.addEventListener('click', function() {
                const isPassword = passwordEl.type === 'password';
                passwordEl.type = isPassword ? 'text' : 'password';
                toggleIcon.className = isPassword ? 'fas fa-eye-slash' : 'fas fa-eye';
                toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
                passwordEl.focus();
            });

            /* ── Field error helpers ─────────────────────────────────────── */
            function setError(inputEl, errorId, message) {
                inputEl.classList.add('is-invalid');
                inputEl.classList.remove('is-valid');
                document.getElementById(errorId).textContent = message;
            }

            function clearError(inputEl, errorId) {
                inputEl.classList.remove('is-invalid');
                document.getElementById(errorId).textContent = '';
            }

            function markValid(inputEl) {
                inputEl.classList.remove('is-invalid');
                inputEl.classList.add('is-valid');
            }

            /* ── Per-field validation ────────────────────────────────────── */
            function validateUsername() {
                const val = usernameEl.value.trim();
                if (!val) {
                    setError(usernameEl, 'username-error', 'Username is required.');
                    return false;
                }
                if (val.length < 3) {
                    setError(usernameEl, 'username-error', 'Username must be at least 3 characters.');
                    return false;
                }
                if (val.length > 100) {
                    setError(usernameEl, 'username-error', 'Username is too long.');
                    return false;
                }
                clearError(usernameEl, 'username-error');
                markValid(usernameEl);
                return true;
            }

            function validatePassword() {
                const val = passwordEl.value;
                if (!val) {
                    setError(passwordEl, 'password-error', 'Password is required.');
                    return false;
                }
                if (val.length < 6) {
                    setError(passwordEl, 'password-error', 'Password must be at least 6 characters.');
                    return false;
                }
                clearError(passwordEl, 'password-error');
                markValid(passwordEl);
                return true;
            }

            /* ── Live validation on blur ─────────────────────────────────── */
            usernameEl.addEventListener('blur', function() {
                if (usernameEl.value.length > 0) validateUsername();
            });

            passwordEl.addEventListener('blur', function() {
                if (passwordEl.value.length > 0) validatePassword();
            });

            /* Clear error as soon as the user starts typing again */
            usernameEl.addEventListener('input', function() {
                if (usernameEl.classList.contains('is-invalid')) {
                    clearError(usernameEl, 'username-error');
                }
            });

            passwordEl.addEventListener('input', function() {
                if (passwordEl.classList.contains('is-invalid')) {
                    clearError(passwordEl, 'password-error');
                }
            });

            /* ── Form submit ─────────────────────────────────────────────── */
            form.addEventListener('submit', function(e) {
                const validUser = validateUsername();
                const validPass = validatePassword();

                if (!validUser || !validPass) {
                    e.preventDefault();
                    /* Focus the first invalid field */
                    if (!validUser) usernameEl.focus();
                    else passwordEl.focus();
                    return;
                }

                /* Prevent double-submit */
                loginBtn.disabled = true;
                loginBtn.classList.add('loading');
            });

            /* Re-enable button if the user navigates back (browser bfcache) */
            window.addEventListener('pageshow', function(e) {
                if (e.persisted) {
                    loginBtn.disabled = false;
                    loginBtn.classList.remove('loading');
                }
            });
        })();
    </script>
</body>

</html>

