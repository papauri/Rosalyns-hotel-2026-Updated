<?php

/**
 * Admin - Licence (owner feature 2026-11-07). Administrator role only.
 *
 * Shows the licence status the system is working under (includes/licence.php), the payment dates, when it last asked the
 * licence panel and why a check failed, and a "Check now" button. Reachable in EVERY licence status (the guard lets it through
 * even when the system is locked), so an administrator can always see where things stand and re-check after paying.
 */

require_once 'admin-init.php';

$user = [
    'id'        => (int)($_SESSION['admin_user_id'] ?? 0),
    'username'  => (string)($_SESSION['admin_username'] ?? ''),
    'role'      => (string)($_SESSION['admin_role'] ?? ''),
    'full_name' => (string)($_SESSION['admin_full_name'] ?? ''),
];
$csrf_token = $csrf_token ?? generateCsrfToken();
$current_page = 'licence.php';

if ($user['role'] !== 'admin') {
    rhDenyAndRedirectHome($user['id'], $user['role'], basename($_SERVER['PHP_SELF']));
    exit;
}

$message = (string)($_SESSION['licence_flash_ok'] ?? '');
$error   = (string)($_SESSION['licence_flash_err'] ?? '');
unset($_SESSION['licence_flash_ok'], $_SESSION['licence_flash_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['licence_flash_err'] = 'Invalid form token. Please refresh the page and try again. Nothing was changed.';
    } elseif (($_POST['action'] ?? '') === 'check_now') {
        $r = rh_licence_check_now();
        if ($r['ok']) {
            $_SESSION['licence_flash_ok'] = 'The licence was checked just now and the answer was accepted.';
        } else {
            $_SESSION['licence_flash_err'] = $r['error'] !== '' ? $r['error'] : 'The check did not run.';
        }
        try {
            if (function_exists('logActivity')) {
                logActivity($user['id'], 'licence_check', 'Licence checked from the Licence page: ' . ($r['ok'] ? 'accepted' : 'not accepted'));
            }
        } catch (Throwable $e) { /* logging never blocks */ }
    } elseif (($_POST['action'] ?? '') === 'change_key' || ($_POST['action'] ?? '') === 'clear_key') {
        // Administrators only (checked above) and only on a licensed site. The key itself is never logged or shown back.
        $act = (string)$_POST['action'];
        if (!rh_licence_enabled()) {
            $_SESSION['licence_flash_err'] = 'Licensing is not switched on for this site, so there is no key to change.';
        } elseif ($act === 'change_key') {
            $r = rh_licence_set_key((string)($_POST['licence_key'] ?? ''));
            if ($r['ok']) {
                $_SESSION['licence_flash_ok'] = 'The new licence key was accepted by the licence service and saved.';
            } else {
                $_SESSION['licence_flash_err'] = ($r['error'] !== '' ? $r['error'] : 'The key could not be tested.') . ' The old key is still in use.';
            }
            $logLine = 'Licence key change from the Licence page: ' . ($r['ok'] ? 'tested and saved' : 'refused, old key kept');
        } else {
            if (rh_licence_clear_key_override()) {
                $r = rh_licence_check_now();
                $_SESSION['licence_flash_ok'] = 'The key saved here was removed. The key from the server settings file is used again'
                    . ($r['ok'] ? ' and the licence service accepted it.' : '. Checking it just now did not succeed: ' . $r['error']);
            } else {
                $_SESSION['licence_flash_err'] = 'The saved key could not be removed. Nothing was changed.';
            }
            $logLine = 'Licence key saved here removed; server file key in use again';
        }
        try {
            if (function_exists('logActivity') && !empty($logLine)) {
                logActivity($user['id'], 'licence_key', $logLine);
            }
        } catch (Throwable $e) { /* logging never blocks */ }
    }
    header('Location: licence.php', true, 303);
    exit;
}

$st = rh_licence_status(true);
$h = static function ($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
$day = static function (?string $ymd): string { return ($ymd !== null && $ymd !== '') ? rh_licence_date_words($ymd) : '—'; };
$when = static function (int $ts): string { return $ts > 0 ? date('j M Y H:i', $ts) : 'never'; };

$statusLabels = [
    'unconfigured' => ['Not licensed', 'muted', 'This system is not on a licence, so nothing is checked or restricted.'],
    'active'       => ['Active', 'ok', 'Everything works normally.'],
    'grace'        => ['Payment overdue', 'warn', 'Everything still works, but the payment is overdue. It becomes read-only on the date below.'],
    'read_only'    => ['Read-only', 'alert', 'Staff can view records, check guests out, record payments and export data. Nothing else can be changed.'],
    'suspended'    => ['Locked', 'alert', 'The staff system is locked. Only this page, sign-in and sign-out, backups and data exports work.'],
];
if (!empty($st['trial'])) {
    // a free trial (owner 2026-11-09): trial words instead of payment words
    $statusLabels['active'] = ['Free trial', 'ok', 'Everything works during the free trial, until ' . rh_licence_date_words((string)$st['trial_until']) . '.'];
    $statusLabels['grace'] = ['Free trial ended', 'warn', 'The free trial has ended. Everything still works for now; contact us to continue.'];
    $statusLabels['read_only'][0] = 'Free trial ended (read-only)';
    $statusLabels['suspended'][0] = 'Free trial ended (locked)';
}
[$label, $pill, $meaning] = $statusLabels[$st['status']] ?? ['Unknown', 'muted', ''];
$d = (array)$st['dates'];
$payload = is_array($st['payload']) ? $st['payload'] : [];
$modules = (isset($st['modules']) && is_array($st['modules'])) ? $st['modules'] : null;
$keyInfo = $st['configured'] ? rh_licence_key_info() : ['key' => '', 'source' => 'none', 'hint' => ''];
$keySourceWords = ['saved' => 'saved here', 'server' => 'from the server settings file', 'none' => 'not set'];
$sourceWords = ['server' => 'the licence service', 'server_override' => 'set by hand at the licence service', 'schedule' => 'the payment dates (the last answer is old)', 'fallback' => 'no licence answer yet', 'none' => '—'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <title>Licence - Admin Panel</title>

    <link rel="icon" href="../favicon.ico" sizes="any">
    <link rel="shortcut icon" href="../favicon.ico">
    <link rel="manifest" href="../manifest.php" crossorigin="use-credentials">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400;1,500&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-styles.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-styles.css'); ?>">
    <link rel="stylesheet" href="css/admin-components.css?v=<?php echo @filemtime(__DIR__ . '/css/admin-components.css'); ?>">
</head>

<body>
    <?php require_once 'includes/admin-header.php'; ?>

    <div class="content licence-page">
        <header class="rh-page-head">
            <div class="rh-page-head__main">
                <div class="rh-page-head__title">
                    <h1>Licence</h1>
                    <span class="rh-pill rh-pill--<?php echo $h($pill); ?>"><?php echo $h($label); ?></span>
                </div>
                <p class="rh-page-head__meta">The licence this system runs under, the payment dates and when it was last checked</p>
            </div>
        </header>

        <?php if ($message !== ''): ?>
            <div class="alert alert-success" role="status"><span><?php echo $h($message); ?></span></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><span><?php echo $h($error); ?></span></div>
        <?php endif; ?>

        <section class="rh-panel">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Status</h2>
                <?php if ($st['configured']): ?>
                    <div class="rh-panel__actions">
                        <form method="POST" action="licence.php">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                            <input type="hidden" name="action" value="check_now">
                            <button type="submit" class="btn btn-primary" data-help="Check now|Asks the licence service for the latest answer right now. Use it after a payment has been made.">Check now</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
            <div class="rh-panel__body">
                <p style="margin:0 0 .75rem"><?php echo $h($meaning); ?></p>
                <?php if ($st['configured'] && $st['message'] !== ''): ?>
                    <p style="margin:0 0 .75rem"><strong>Note:</strong> <?php echo $h($st['message']); ?></p>
                <?php endif; ?>
                <?php if ($st['configured']): ?>
                    <table class="rh-kv">
                        <tr><th scope="row">Status comes from</th><td><?php echo $h($sourceWords[$st['source']] ?? '—'); ?><br><small>Where the status above was worked out from.</small></td></tr>
                        <tr><th scope="row">Paid until</th><td><?php echo $h($day($d['paid_until'] ?? null)); ?><br><small>The last day that is paid for. After it the payment is overdue.</small></td></tr>
                        <tr><th scope="row">Read-only from</th><td><?php echo $h($day($d['read_only_from'] ?? null)); ?><br><small>The grace period (<?php echo (int)($payload['grace_days'] ?? 0); ?> days) ends the day before. From this date staff can only view, check out, record payments and export.</small></td></tr>
                        <tr><th scope="row">Locked from</th><td><?php echo $h($day($d['suspended_from'] ?? null)); ?><br><small>After <?php echo (int)($payload['readonly_days'] ?? 0); ?> days of read-only the staff system locks. The guest website keeps running.</small></td></tr>
                        <tr><th scope="row">Last checked</th><td><?php echo $h($when((int)$st['last_check_at'])); ?><br><small>The last time this system asked the licence service. It checks by itself every hour, and straight away when the provider changes your licence.</small></td></tr>
                        <tr><th scope="row">Last problem</th><td><?php echo $st['last_error'] !== '' ? $h($st['last_error']) : 'None'; ?><br><small>Why the last check failed, if it did. A failed check never locks the system by itself.</small></td></tr>
                        <tr><th scope="row">Business type</th><td><?php echo $h($st['business_type'] ?? '—'); ?><br><small>What the licence says this system is used for.</small></td></tr>
                        <tr><th scope="row">Licensed modules</th><td><?php echo $modules === null ? 'Not stated' : ($modules ? $h(implode(', ', $modules)) : 'None listed'); ?><br><small>Shown for information. Switching features on or off by licence is not applied yet.</small></td></tr>
                    </table>
                <?php else: ?>
                    <p style="margin:0">To switch licensing on for this system, the licence key is added to the program and the licence details are added to this site's <code>.env</code> settings by the system provider.</p>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($st['configured']): ?>
        <section class="rh-panel">
            <div class="rh-panel__head">
                <h2 class="rh-panel__title">Licence key</h2>
            </div>
            <div class="rh-panel__body">
                <p style="margin:0 0 .75rem">The key your provider gave you (also called the API key). The system uses it every time it asks the licence service for your licence.</p>
                <table class="rh-kv">
                    <tr><th scope="row">Current key</th><td><?php echo $keyInfo['hint'] !== '' ? 'ends in ' . $h($keyInfo['hint']) : ($keyInfo['source'] === 'none' ? 'none' : 'hidden'); ?> (<?php echo $h($keySourceWords[$keyInfo['source']] ?? ''); ?>)<br><small>Only the last four characters are ever shown.</small></td></tr>
                </table>
                <form method="POST" action="licence.php" autocomplete="off" style="margin-top:1rem">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                    <input type="hidden" name="action" value="change_key">
                    <div class="form-group">
                        <label for="licence_key">Change licence key</label>
                        <input type="password" id="licence_key" name="licence_key" class="form-control" autocomplete="off" maxlength="200" required>
                        <small>The new key is tried with the licence service first. It is saved only if the service accepts it; otherwise the old key stays.</small>
                    </div>
                    <button type="submit" class="btn btn-primary">Test and save</button>
                </form>
                <?php if ($keyInfo['source'] === 'saved'): ?>
                    <form method="POST" action="licence.php" style="margin-top:.75rem" onsubmit="return confirm('Go back to the key in the server settings file?');">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrf_token); ?>">
                        <input type="hidden" name="action" value="clear_key">
                        <button type="submit" class="btn btn-secondary" data-help="Use the server file key again|Removes the key saved here. Checks go back to using the key in this site's server settings file.">Use the server file key again</button>
                    </form>
                <?php endif; ?>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <?php require_once 'includes/admin-footer.php'; ?>
</body>

</html>
