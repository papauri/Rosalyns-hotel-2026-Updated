/**
 * admin-pwa-install.js — PWA install prompt for admin staff.
 *
 * Triggers after `beforeinstallprompt`; shows a slim bar docked to the bottom edge of the
 * screen (beside the sidebar on desktop). It sits below the navigation and every modal, is
 * hidden while the navigation drawer or any modal is open, and a dismiss is remembered
 * (localStorage, with an in-memory fallback when storage is blocked) for the window set in
 * Admin -> Booking Settings -> PWA Install Banner (14 days by default).
 */
(function () {
    'use strict';

    const DISMISS_KEY = 'rh_admin_pwa_dismissed';
    // Dismiss duration is controlled via Admin → Booking Settings → PWA Install Banner.
    // Falls back to 14 days if the global hasn't been set yet.
    const DISMISS_DAYS = (typeof window.RH_PWA_DISMISS_DAYS === 'number' && window.RH_PWA_DISMISS_DAYS > 0)
        ? window.RH_PWA_DISMISS_DAYS
        : 14;

    var dismissedInMemory = false; // storage blocked (private mode): stay dismissed until reload

    function isDismissed() {
        if (dismissedInMemory) return true;
        try {
            const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            return ts > 0 && (Date.now() - ts) < DISMISS_DAYS * 86400 * 1000;
        } catch (e) { return false; }
    }

    function markDismissed() {
        dismissedInMemory = true;
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) { /* noop */ }
    }

    let deferredPrompt = null;
    let bannerEl = null;

    /* The banner is fixed at top-right, which on the POS till and the station displays lands
     * directly on top of the toolbar's right-hand controls — 86, Help, More and Sign out became
     * physically unclickable, because the banner won the hit test. Those screens are worked all
     * shift by staff who are not the person deciding to install an app, and the 14-day dismiss
     * window meant it came back and re-blocked the same buttons periodically. Suppress it there;
     * the dashboard and every other admin page still offer it, which is where a manager would
     * install from anyway. */
    function isFrontOfHouseScreen() {
        const b = document.body;
        return !!b && (b.classList.contains('pos-screen') || b.classList.contains('station-screen'));
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        if (isDismissed() || isFrontOfHouseScreen()) {
            // If the banner is in dismiss cooldown, do not intercept the native prompt lifecycle.
            return;
        }
        e.preventDefault();
        deferredPrompt = e;
        // Show quickly to avoid losing the prompt event on short-lived page visits.
        requestAnimationFrame(showBanner);
    });

    window.addEventListener('appinstalled', function () {
        hideBanner();
        deferredPrompt = null;
    });

    function showBanner() {
        if (bannerEl || !deferredPrompt) return;

        injectStyles();

        bannerEl = document.createElement('div');
        bannerEl.id = 'admin-pwa-banner';
        bannerEl.setAttribute('role', 'region');
        bannerEl.setAttribute('aria-label', 'Install admin app');
        bannerEl.innerHTML = [
            '<i class="fas fa-hotel admin-pwa-banner__icon" aria-hidden="true"></i>',
            '<div class="admin-pwa-banner__text" id="admin-pwa-body">',
            '  <strong>Install Admin App</strong>',
            '  <span>Run POS &amp; KDS without a browser tab.</span>',
            '</div>',
            '<button class="admin-pwa-banner__install" id="admin-pwa-install" type="button">Install</button>',
            '<button class="admin-pwa-banner__dismiss" id="admin-pwa-dismiss" type="button" aria-label="Dismiss">',
            '  <i class="fas fa-times" aria-hidden="true"></i>',
            '</button>',
        ].join('');

        document.body.appendChild(bannerEl);
        syncVisibility();
        requestAnimationFrame(function () { if (bannerEl) bannerEl.classList.add('is-visible'); });

        document.getElementById('admin-pwa-install').addEventListener('click', doInstall);
        document.getElementById('admin-pwa-dismiss').addEventListener('click', dismiss);
        watchOverlays();
    }

    /* Hidden while the navigation drawer or any modal is open, so it never competes with them. */
    var MODAL_SELECTOR = '.modal-overlay, .modal, .mm-modal, .admin-modal-overlay, .rt-modal-overlay, .rp-modal-overlay, .sup-modal-bg, .dashboard-insight-modal, .overlay';

    function overlayOpen() {
        if (document.querySelector('.admin-nav.nav-open')) return true;
        var list = document.querySelectorAll(MODAL_SELECTOR);
        for (var i = 0; i < list.length; i++) {
            var el = list[i];
            if (el === bannerEl) continue;
            var cs = window.getComputedStyle(el);
            if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden' || parseFloat(cs.opacity) === 0) continue;
            var r = el.getBoundingClientRect();
            if (r.width > 0 && r.height > 0) return true;
        }
        return false;
    }

    function syncVisibility() {
        if (!bannerEl) return;
        var open = overlayOpen();
        if (bannerEl.hidden !== open) bannerEl.hidden = open; // no self-triggered mutation loop
        document.body.classList.toggle('rh-pwa-bar-on', !open);
    }

    var overlayObserver = null, syncQueued = false;
    function queueSync() {
        if (syncQueued) return;
        syncQueued = true;
        requestAnimationFrame(function () { syncQueued = false; syncVisibility(); });
    }
    function watchOverlays() {
        if (overlayObserver || !window.MutationObserver) return;
        overlayObserver = new MutationObserver(queueSync);
        overlayObserver.observe(document.body, { attributes: true, attributeFilter: ['class', 'style', 'hidden', 'aria-hidden'], subtree: true, childList: true });
    }

    function hideBanner() {
        if (!bannerEl) return;
        if (overlayObserver) { overlayObserver.disconnect(); overlayObserver = null; }
        document.body.classList.remove('rh-pwa-bar-on');
        if (bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
        bannerEl = null;
    }

    function dismiss() { markDismissed(); hideBanner(); }

    function doInstall() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choice) {
            if (choice.outcome === 'accepted') hideBanner();
            else hideBanner(); // cancelled native dialog — don't start dismiss cooldown; banner returns on next page
            deferredPrompt = null;
        });
    }

    function injectStyles() {
        if (document.getElementById('admin-pwa-styles')) return;
        const s = document.createElement('style');
        s.id = 'admin-pwa-styles';
        s.textContent = [
            '#admin-pwa-banner {',
            '  position: fixed;',
            '  left: var(--admin-current-sidebar-width, 0px); right: 0; bottom: 0;',
            '  z-index: 990;', // below the header (1000), the nav (999+) and every modal (9998+)
            '  display: flex;',
            '  align-items: center;',
            '  gap: 12px;',
            '  box-sizing: border-box;',
            '  background: #1f1f24;',
            '  border-top: 1px solid rgba(138,119,95,0.4);',
            '  color: #F7F3EE;',
            '  padding: 8px 72px calc(8px + env(safe-area-inset-bottom, 0px)) 16px;', // right gap keeps the draggable help button from covering Dismiss
            '  font-family: "Inter", system-ui, sans-serif;',
            '  font-size: 13px;',
            '  opacity: 0;',
            '  transition: opacity 0.2s ease;',
            '}',
            '#admin-pwa-banner[hidden] { display: none; }',
            '#admin-pwa-banner.is-visible { opacity: 1; }',
            'body.rh-pwa-bar-on { padding-bottom: 64px; }',
            '.admin-pwa-banner__icon { flex-shrink: 0; font-size: 18px; color: #D2AE72; }',
            '.admin-pwa-banner__text { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 1px; }',
            '.admin-pwa-banner__text strong { font-size: 13px; font-weight: 600; }',
            '.admin-pwa-banner__text span { font-size: 12px; color: rgba(247,243,238,0.7); }',
            '.admin-pwa-banner__install {',
            '  flex-shrink: 0;',
            '  min-height: 44px;',
            '  background: #766550;',
            '  color: #fff;',
            '  border: none;',
            '  border-radius: 8px;',
            '  padding: 0 18px;',
            '  font-size: 13px;',
            '  font-weight: 600;',
            '  cursor: pointer;',
            '  font-family: inherit;',
            '  white-space: nowrap;',
            '}',
            '.admin-pwa-banner__install:hover { background: #655642; }',
            '.admin-pwa-banner__dismiss {',
            '  flex-shrink: 0;',
            '  width: 44px; min-height: 44px;',
            '  background: transparent;',
            '  border: none;',
            '  color: rgba(247,243,238,0.7);',
            '  font-size: 14px;',
            '  cursor: pointer;',
            '  border-radius: 8px;',
            '}',
            '.admin-pwa-banner__dismiss:hover { color: #fff; background: rgba(255,255,255,0.08); }',
            '@media (max-width: 480px) {',
            '  #admin-pwa-banner { padding-left: 12px; padding-right: 68px; gap: 8px; }',
            '  .admin-pwa-banner__text span { display: none; }',
            '}',
        ].join('\n');
        document.head.appendChild(s);
    }
})();
