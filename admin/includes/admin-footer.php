</div>

<!-- Simple Admin Footer -->
<footer style="background: var(--deep-navy); color: white; padding: 15px 20px; text-align: center; border-top: 3px solid var(--gold);">
    <div class="container">
        <p style="margin: 0; opacity: 0.9; font-size: 14px;">
            &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars(getSetting('site_name')); ?>
        </p>
    </div>
</footer>

<!-- Admin Components (Modal, Alert/Toast, AdminConfirm, ButtonLoader) — guard prevents double-init -->
<script src="js/admin-components.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-components.js'); ?>"></script>
<!-- Admin Core JS (burger menu, nav, modals) -->
<script src="js/admin-main.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-main.js'); ?>"></script>
<!-- Global table-section pagination (10 rows max + inline loader) -->
<script src="js/admin-section-pagination.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-section-pagination.js'); ?>"></script>
<!-- Admin Mobile Enhancements -->
<script src="js/admin-mobile.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-mobile.js'); ?>"></script>
<!-- Deep-linking: scroll to + flash the targeted row/card/section from ?focus= or #hash -->
<script src="js/admin-deeplink.js?v=<?php echo filemtime(__DIR__ . '/../js/admin-deeplink.js'); ?>"></script>
<!-- PWA install prompt — shows "Install App" banner on Chrome/Edge desktop + Android -->
<script src="js/pwa-install.js" defer></script>
<!-- Universal offline queue + connectivity banner. Only intercepts forms with
     data-offline-queue="1" attribute; only displays the banner when offline
     or when there are pending sync items. Safe to include on every admin page. -->
<?php require __DIR__ . '/offline-banner.php'; ?>
<!-- Shared session flash toasts — renders any unconsumed success/error session messages -->
<?php require __DIR__ . '/admin-flash.php'; ?>

</body>

</html>

