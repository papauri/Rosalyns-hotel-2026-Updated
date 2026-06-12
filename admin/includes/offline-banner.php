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
?>
<script src="<?php echo htmlspecialchars($offlineQueueSrc, ENT_QUOTES, 'UTF-8'); ?>" defer></script>
<?php } ?>

