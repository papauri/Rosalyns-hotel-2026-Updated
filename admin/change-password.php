<?php

/**
 * Self-service password change — any logged-in staff member.
 *
 * Step 1: current password + new password → 6-digit OTP emailed.
 * Step 2: enter OTP → password updated. State lives in $_SESSION['pwc']
 * (see includes/password-change-lib.php for the testable core).
 *
 * Deliberately unlisted in getPermissionForPage(): every employee may
 * change their own password regardless of role permissions.
 */
require_once 'admin-init.php';
require_once __DIR__ . '/includes/password-change-lib.php';
require_once __DIR__ . '/../includes/alert.php';

/** @var PDO $pdo */
/** @var array $user */
/** @var string $csrf_token */

$pwc_message = '';
$pwc_error = '';
$pwc_stage = !empty($_SESSION['pwc']) && time() <= (int)($_SESSION['pwc']['expires'] ?? 0) ? 'otp' : 'form';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $pwc_error = 'Security token invalid — refresh the page.';
    } elseif (($_POST['pwc_action'] ?? '') === 'start') {
        $_SESSION['pwc'] = $_SESSION['pwc'] ?? [];
        $res = pwc_start(
            $_SESSION['pwc'],
            $pdo,
            (int)$user['id'],
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? '')
        );
        if ($res['success']) {
            require_once __DIR__ . '/../config/email.php';
            $mail = sendPasswordChangeOtpEmail((string)$_SESSION['pwc']['email'], (string)$_SESSION['pwc']['name'], (string)$res['otp']);
            if (!empty($mail['success'])) {
                $pwc_stage = 'otp';
                $pwc_message = 'A 6-digit verification code has been emailed to ' . htmlspecialchars(preg_replace('/^(.).*(.@)/', '$1***$2', (string)$_SESSION['pwc']['email'])) . '. Enter it below within 10 minutes.';
            } else {
                $_SESSION['pwc'] = [];
                $pwc_error = 'Could not send the verification email — try again or contact your administrator.';
            }
        } else {
            $pwc_error = $res['message'];
        }
    } elseif (($_POST['pwc_action'] ?? '') === 'confirm') {
        $_SESSION['pwc'] = $_SESSION['pwc'] ?? [];
        $res = pwc_confirm($_SESSION['pwc'], $pdo, (int)$user['id'], (string)($_POST['otp'] ?? ''));
        if (!empty($res['done'])) {
            $pwc_stage = 'done';
            $pwc_message = 'Your password has been changed. Use it from your next sign-in.';
        } else {
            $pwc_error = $res['message'];
            $pwc_stage = !empty($_SESSION['pwc']) ? 'otp' : 'form';
        }
    } elseif (($_POST['pwc_action'] ?? '') === 'cancel') {
        $_SESSION['pwc'] = [];
        $pwc_stage = 'form';
        $pwc_message = 'Password change cancelled.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password — Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Serif+Display&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
    <style>
        .pwc-card { max-width: 480px; margin: 0 auto; background: #fff; border: 1px solid #d5cfc4; border-radius: 4px; padding: 30px 32px; }
        .pwc-card h2 { margin: 0 0 6px; font-size: 1.15rem; color: #3e3930; }
        .pwc-sub { font-size: .85rem; color: #7a6f63; margin: 0 0 22px; line-height: 1.55; }
        .pwc-field { margin-bottom: 16px; }
        .pwc-field label { display: block; font-size: .8rem; font-weight: 600; color: #5a5147; margin-bottom: 6px; }
        .pwc-field input { width: 100%; padding: 10px 12px; border: 1px solid #d3cbc0; border-radius: 3px; font-size: .92rem; box-sizing: border-box; }
        .pwc-field input:focus { outline: none; border-color: #8B7355; }
        .pwc-otp-input { text-align: center; font-size: 1.6rem !important; letter-spacing: 12px; font-weight: 700; }
        .pwc-btn { width: 100%; background: #8B7355; color: #fff; border: none; border-radius: 3px; padding: 12px; font-size: .92rem; font-weight: 600; cursor: pointer; }
        .pwc-btn:hover { background: #6e5a3e; }
        .pwc-cancel { width: 100%; background: none; border: none; color: #9a8f82; font-size: .82rem; margin-top: 12px; cursor: pointer; text-decoration: underline; }
        .pwc-note { background: #fffbeb; border: 1px solid #f6c90e; border-radius: 4px; padding: 10px 14px; font-size: .8rem; color: #7a5f00; margin-bottom: 18px; }
        .pwc-msg { border-radius: 4px; padding: 12px 16px; font-size: .86rem; margin-bottom: 18px; }
        .pwc-msg--ok { background: #e8f5e9; border: 1px solid #a5d6a7; color: #1b5e20; }
        .pwc-msg--err { background: #fdecea; border: 1px solid #f5c6cb; color: #86201a; }
        .pwc-done { text-align: center; padding: 20px 0 6px; }
        .pwc-done i { font-size: 2.4rem; color: #2e7d32; margin-bottom: 12px; }
    </style>
</head>
<body>
    <?php require_once 'includes/admin-header.php'; ?>
    <?php require_once 'includes/admin-flash.php'; ?>

    <div class="content">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-key" style="color:#8B7355;margin-right:10px;"></i> Change Password</h1>
        </div>

        <div class="pwc-card">
            <?php if ($pwc_message): ?><div class="pwc-msg pwc-msg--ok"><i class="fas fa-circle-check"></i> <?php echo $pwc_message; ?></div><?php endif; ?>
            <?php if ($pwc_error): ?><div class="pwc-msg pwc-msg--err"><i class="fas fa-triangle-exclamation"></i> <?php echo htmlspecialchars($pwc_error); ?></div><?php endif; ?>

            <?php if ($pwc_stage === 'done'): ?>
                <div class="pwc-done">
                    <i class="fas fa-circle-check"></i>
                    <h2>Password updated</h2>
                    <p class="pwc-sub">Your new password takes effect at your next sign-in.</p>
                    <a href="dashboard.php" class="pwc-btn" style="display:inline-block;width:auto;padding:12px 28px;text-decoration:none;">Back to Admin</a>
                </div>
            <?php elseif ($pwc_stage === 'otp'): ?>
                <h2>Enter verification code</h2>
                <p class="pwc-sub">We emailed a 6-digit code to your address on file. It expires in 10 minutes.</p>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="pwc_action" value="confirm">
                    <div class="pwc-field">
                        <label for="pwc_otp">Verification code</label>
                        <input class="pwc-otp-input" type="text" id="pwc_otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" required autofocus placeholder="&bull;&bull;&bull;&bull;&bull;&bull;">
                    </div>
                    <button type="submit" class="pwc-btn"><i class="fas fa-check"></i> Confirm &amp; Change Password</button>
                </form>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="pwc_action" value="cancel">
                    <button type="submit" class="pwc-cancel">Cancel and start over</button>
                </form>
            <?php else: ?>
                <h2>Change your password</h2>
                <p class="pwc-sub">Signed in as <strong><?php echo htmlspecialchars((string)$user['username']); ?></strong>. For your security we'll email a verification code to your address on file before anything changes.</p>
                <div class="pwc-note"><i class="fas fa-shield-halved"></i> Minimum 8 characters. Avoid reusing passwords from other sites.</div>
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="pwc_action" value="start">
                    <div class="pwc-field">
                        <label for="pwc_current">Current password</label>
                        <input type="password" id="pwc_current" name="current_password" required autocomplete="current-password">
                    </div>
                    <div class="pwc-field">
                        <label for="pwc_new">New password</label>
                        <input type="password" id="pwc_new" name="new_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <div class="pwc-field">
                        <label for="pwc_confirm">Confirm new password</label>
                        <input type="password" id="pwc_confirm" name="confirm_password" required minlength="8" autocomplete="new-password">
                    </div>
                    <button type="submit" class="pwc-btn"><i class="fas fa-envelope"></i> Email Me a Verification Code</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>
</html>
