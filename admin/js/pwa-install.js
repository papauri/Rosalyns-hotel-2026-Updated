/**
 * admin-pwa-install.js — PWA install prompt for admin staff.
 *
 * Triggers after `beforeinstallprompt`; shows an unobtrusive toast-style banner
 * anchored to the bottom-right so it doesn't obscure the admin nav/content.
 * Banner appears once per session after first interaction with the admin, then
 * respects a 14-day localStorage dismiss window.
 */
(function () {
    'use strict';

    const DISMISS_KEY = 'rh_admin_pwa_dismissed';
    // Dismiss duration is controlled via Admin → Booking Settings → PWA Install Banner.
    // Falls back to 14 days if the global hasn't been set yet.
    const DISMISS_DAYS = (typeof window.RH_PWA_DISMISS_DAYS === 'number' && window.RH_PWA_DISMISS_DAYS > 0)
        ? window.RH_PWA_DISMISS_DAYS
        : 14;

    function isDismissed() {
        try {
            const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            return ts > 0 && (Date.now() - ts) < DISMISS_DAYS * 86400 * 1000;
        } catch (e) { return false; }
    }

    function markDismissed() {
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) { /* noop */ }
    }

    let deferredPrompt = null;
    let bannerEl = null;

    window.addEventListener('beforeinstallprompt', function (e) {
        if (isDismissed()) {
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
            '<div class="admin-pwa-banner__text">',
            '  <strong>Install Admin App</strong>',
            '  <span>Run POS &amp; KDS without a browser tab.</span>',
            '</div>',
            '<button class="admin-pwa-banner__install" id="admin-pwa-install" type="button">Install</button>',
            '<button class="admin-pwa-banner__dismiss" id="admin-pwa-dismiss" type="button" aria-label="Dismiss">',
            '  <i class="fas fa-times" aria-hidden="true"></i>',
            '</button>',
        ].join('');

        document.body.appendChild(bannerEl);
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { bannerEl.classList.add('is-visible'); });
        });

        document.getElementById('admin-pwa-install').addEventListener('click', doInstall);
        document.getElementById('admin-pwa-dismiss').addEventListener('click', dismiss);
    }

    function hideBanner() {
        if (!bannerEl) return;
        bannerEl.classList.remove('is-visible');
        setTimeout(function () {
            if (bannerEl && bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
            bannerEl = null;
        }, 350);
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
            '  bottom: 20px; right: 20px;',
            '  z-index: 9100;',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 10px;',
            '  background: #1f1f24;',
            '  border: 1px solid rgba(138,119,95,0.4);',
            '  color: #F7F3EE;',
            '  padding: 12px 14px;',
            '  border-radius: 12px;',
            '  box-shadow: 0 8px 32px rgba(0,0,0,0.5);',
            '  max-width: 360px;',
            '  transform: translateY(20px);',
            '  opacity: 0;',
            '  transition: transform 0.3s ease, opacity 0.3s ease;',
            '  font-family: "Inter", system-ui, sans-serif;',
            '  font-size: 13px;',
            '}',
            '#admin-pwa-banner.is-visible { transform: translateY(0); opacity: 1; }',
            '.admin-pwa-banner__icon {',
            '  flex-shrink: 0;',
            '  font-size: 20px;',
            '  color: #8A775F;',
            '}',
            '.admin-pwa-banner__text {',
            '  flex: 1;',
            '  display: flex;',
            '  flex-direction: column;',
            '  gap: 1px;',
            '}',
            '.admin-pwa-banner__text strong { font-size: 13px; font-weight: 600; }',
            '.admin-pwa-banner__text span { font-size: 11px; color: rgba(247,243,238,0.55); }',
            '.admin-pwa-banner__install {',
            '  flex-shrink: 0;',
            '  background: #8A775F;',
            '  color: #fff;',
            '  border: none;',
            '  border-radius: 7px;',
            '  padding: 7px 14px;',
            '  font-size: 12px;',
            '  font-weight: 600;',
            '  cursor: pointer;',
            '  font-family: inherit;',
            '  white-space: nowrap;',
            '  transition: opacity 0.15s;',
            '}',
            '.admin-pwa-banner__install:hover { opacity: 0.85; }',
            '.admin-pwa-banner__dismiss {',
            '  flex-shrink: 0;',
            '  background: transparent;',
            '  border: none;',
            '  color: rgba(247,243,238,0.4);',
            '  font-size: 14px;',
            '  padding: 6px;',
            '  cursor: pointer;',
            '  border-radius: 5px;',
            '  transition: color 0.15s;',
            '}',
            '.admin-pwa-banner__dismiss:hover { color: rgba(247,243,238,0.8); }',
            '@media (max-width: 480px) {',
            '  #admin-pwa-banner { bottom: 10px; right: 10px; left: 10px; max-width: none; }',
            '}',
        ].join('\n');
        document.head.appendChild(s);
    }
})();
