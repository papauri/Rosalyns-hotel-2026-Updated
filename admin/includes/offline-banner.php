<?php
/**
 * offline-banner.php — One-line include that wires the SW + offline queue + banner.
 *
 *   Now included universally via admin-footer.php so every admin page gets the banner
 *   automatically. Forms must opt-in to offline queueing with `data-offline-queue="1"`.
 *   The banner UI only renders when offline or when sync items are pending.
 *
 *   Idempotent: a redundant include from legacy pages (kds.php / pos.php / stock-orders.php)
 *   is a no-op because we guard with a static flag.
 */
if (!isset($GLOBALS['__rh_offline_banner_emitted'])) {
    $GLOBALS['__rh_offline_banner_emitted'] = true;
    $offlineQueueSrc = function_exists('siteUrl')
        ? siteUrl('admin/includes/offline-queue.js')
        : 'includes/offline-queue.js';
    // Current session's CSRF token, so a queued form replayed after a fresh sign-in is not refused.
    $offlineQueueCsrf = function_exists('generateCsrfToken') ? (string)generateCsrfToken() : (string)($_SESSION['csrf_token'] ?? '');
?>
<?php
    // Who is signed in: a sale queued offline is stamped with this person and only replayed under them.
    $offlineQueueUser = [
        'id'   => (int)($_SESSION['admin_user_id'] ?? 0),
        'name' => (string)(($_SESSION['admin_full_name'] ?? '') !== '' ? $_SESSION['admin_full_name'] : ($_SESSION['admin_username'] ?? '')),
    ];
?>
<script>window.RH_OFFLINE_CSRF = <?php echo json_encode($offlineQueueCsrf, JSON_HEX_TAG); ?>; window.RH_OFFLINE_USER = <?php echo json_encode($offlineQueueUser, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?>;</script>
<script src="<?php echo htmlspecialchars($offlineQueueSrc, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php } ?>

