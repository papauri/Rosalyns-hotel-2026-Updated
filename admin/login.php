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
require_once __DIR__ . '/../includes/admin-session.php';
rh_admin_session_start(); // 8h idle sign-out

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

    // Keep redirects inside admin pages only. Strip a leading "admin" segment
    // whether or not it carries a trailing slash — a bare "admin" used to survive
    // this and resolve relative to /admin/, producing /admin/admin.
    $decoded = ltrim($decoded, '/');
    if ($decoded === 'admin' || str_starts_with($decoded, 'admin/')) {
        $decoded = ltrim(substr($decoded, 5), '/');
    }

    if ($decoded === '' || str_starts_with(strtolower($decoded), 'login.php') || str_contains($decoded, '..')) {
        return '';
    }

    if (preg_match('/^[A-Za-z0-9._\-\/\?&=%#]+$/', $decoded) !== 1) {
        return '';
    }

    // The target must name an actual admin page. Without this any bare path
    // segment passes the character check above and is emitted as a relative
    // Location, which the browser resolves against /admin/ into a 404.
    $targetPath = strtolower((string)preg_replace('/[?#].*$/', '', $decoded));
    if (substr($targetPath, -4) !== '.php') {
        return '';
    }

    return $decoded;
}

/**
 * Where to land after login. Decided from the user's permissions (see
 * rhUserHomePage), so an account that cannot open the dashboard is never sent
 * there; the role only picks which of their pages comes first.
 */
function admin_default_route_for_user(int $userId, string $role): string
{
    // The already-signed-in check near the top runs before the database is
    // loaded; it falls back to the role route, and admin-init.php sends the user
    // on to a page they can open if that one is not it.
    if (!isset($GLOBALS['pdo'])) {
        return admin_default_route_for_role($role);
    }
    require_once __DIR__ . '/includes/permissions.php';
    try {
        return rhUserHomePage($userId, $role) ?? admin_default_route_for_role($role);
    } catch (Throwable $e) {
        return admin_default_route_for_role($role);
    }
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

    header('Location: ' . admin_default_route_for_user((int)$_SESSION['admin_user_id'], (string)($_SESSION['admin_role'] ?? '')));
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
    $username = substr(trim((string)($_POST['username'] ?? '')), 0, 100); // admin_activity_log.username is VARCHAR(100)
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
                        session_regenerate_id(true); // new session id on privilege change (anti fixation)
                        // Successful login
                        $_SESSION['admin_user_id'] = $user['id'];
                        $_SESSION['admin_last_activity'] = time();
                        unset($_SESSION['admin_logout_reason']);
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
                        header('Location: ' . admin_default_route_for_user((int)$user['id'], (string)$user['role']));
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
                    <i class="fas fa-hotel"></i>
                </div>
                <h1>Welcome back</h1>
                <p>Sign in to the <?php echo htmlspecialchars($site_name); ?> admin portal.</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert-danger">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['admin_logout_reason']) || ($_GET['reason'] ?? '') === 'idle'): unset($_SESSION['admin_logout_reason']); ?>
                <div class="alert-success">
                    You were signed out after 8 hours without activity. Please sign in again.
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
        </main>
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

