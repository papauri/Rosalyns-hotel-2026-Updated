/**
 * pwa-install.js — PWA install-prompt handler for Rosalyn's Hotel public website.
 *
 * Registers the public service worker and shows a tasteful install banner when
 * the browser fires `beforeinstallprompt` (Chrome/Edge desktop + Android).
 * Safari/iOS uses the apple-touch-icon + Add to Home Screen manually — no JS needed.
 *
 * Banner is shown once; dismissed state is stored in localStorage for 30 days.
 */
(function () {
    'use strict';

    // ── Service Worker registration ───────────────────────────────────────────
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/public-sw.js', { scope: '/' })
            .catch(function () { /* silent — SW is an enhancement */ });
    }

    // ── Install prompt ────────────────────────────────────────────────────────
    const DISMISS_KEY = 'pwa_install_dismissed';
    const DISMISS_DAYS = 30;

    function isDismissed() {
        try {
            const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            return ts > 0 && Date.now() - ts < DISMISS_DAYS * 86400 * 1000;
        } catch (e) {
            return false;
        }
    }

    function markDismissed() {
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) { /* noop */ }
    }

    let deferredPrompt = null;
    let bannerEl = null;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;

        if (isDismissed()) return;
        showBanner();
    });

    window.addEventListener('appinstalled', function () {
        hideBanner();
        deferredPrompt = null;
    });

    function showBanner() {
        if (bannerEl) return; // already mounted

        bannerEl = document.createElement('div');
        bannerEl.id = 'pwa-install-banner';
        bannerEl.setAttribute('role', 'region');
        bannerEl.setAttribute('aria-label', 'Install app');
        bannerEl.innerHTML = [
            '<div class="pwa-banner__inner">',
            '  <div class="pwa-banner__icon"><i class="fas fa-hotel" aria-hidden="true"></i></div>',
            '  <div class="pwa-banner__text">',
            '    <strong>Add to your device</strong>',
            '    <span>Install ' + (window._siteName || 'our app') + ' for quick access, offline support &amp; a native feel.</span>',
            '  </div>',
            '  <button class="pwa-banner__btn pwa-banner__install" id="pwa-install-btn" type="button">Install</button>',
            '  <button class="pwa-banner__btn pwa-banner__dismiss" id="pwa-dismiss-btn" type="button" aria-label="Dismiss">',
            '    <i class="fas fa-times" aria-hidden="true"></i>',
            '  </button>',
            '</div>',
        ].join('');

        injectStyles();
        document.body.appendChild(bannerEl);

        // Animate in
        requestAnimationFrame(function () {
            requestAnimationFrame(function () { bannerEl.classList.add('is-visible'); });
        });

        document.getElementById('pwa-install-btn').addEventListener('click', triggerInstall);
        document.getElementById('pwa-dismiss-btn').addEventListener('click', dismiss);
    }

    function hideBanner() {
        if (!bannerEl) return;
        bannerEl.classList.remove('is-visible');
        setTimeout(function () {
            if (bannerEl && bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
            bannerEl = null;
        }, 380);
    }

    function dismiss() {
        markDismissed();
        hideBanner();
    }

    function triggerInstall() {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (choice) {
            if (choice.outcome === 'accepted') hideBanner();
            else dismiss();
            deferredPrompt = null;
        });
    }

    function injectStyles() {
        if (document.getElementById('pwa-install-styles')) return;
        const style = document.createElement('style');
        style.id = 'pwa-install-styles';
        style.textContent = [
            '#pwa-install-banner {',
            '  position: fixed;',
            '  bottom: 0; left: 0; right: 0;',
            '  z-index: 9000;',
            '  transform: translateY(100%);',
            '  transition: transform 0.32s cubic-bezier(0.4, 0, 0.2, 1);',
            '  font-family: "Jost", sans-serif;',
            '}',
            '#pwa-install-banner.is-visible { transform: translateY(0); }',
            '.pwa-banner__inner {',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 12px;',
            '  background: #231F1C;',
            '  color: #F7F3EE;',
            '  padding: 14px 18px;',
            '  padding-bottom: calc(14px + env(safe-area-inset-bottom));',
            '  box-shadow: 0 -4px 24px rgba(0,0,0,0.28);',
            '}',
            '.pwa-banner__icon {',
            '  flex-shrink: 0;',
            '  width: 40px; height: 40px;',
            '  border-radius: 10px;',
            '  background: #8A775F;',
            '  display: flex; align-items: center; justify-content: center;',
            '  font-size: 18px; color: #fff;',
            '}',
            '.pwa-banner__text {',
            '  flex: 1;',
            '  min-width: 0;',
            '  display: flex;',
            '  flex-direction: column;',
            '  gap: 2px;',
            '}',
            '.pwa-banner__text strong {',
            '  font-size: 14px;',
            '  font-weight: 600;',
            '  color: #F7F3EE;',
            '  white-space: nowrap;',
            '}',
            '.pwa-banner__text span {',
            '  font-size: 12px;',
            '  color: rgba(247,243,238,0.65);',
            '  white-space: nowrap;',
            '  overflow: hidden;',
            '  text-overflow: ellipsis;',
            '}',
            '.pwa-banner__btn {',
            '  flex-shrink: 0;',
            '  border: none;',
            '  cursor: pointer;',
            '  font-family: inherit;',
            '  transition: opacity 0.15s;',
            '}',
            '.pwa-banner__btn:hover { opacity: 0.82; }',
            '.pwa-banner__install {',
            '  background: #8A775F;',
            '  color: #fff;',
            '  font-size: 13px;',
            '  font-weight: 600;',
            '  padding: 9px 18px;',
            '  border-radius: 8px;',
            '  white-space: nowrap;',
            '}',
            '.pwa-banner__dismiss {',
            '  background: transparent;',
            '  color: rgba(247,243,238,0.5);',
            '  font-size: 16px;',
            '  padding: 8px;',
            '  min-width: 36px; min-height: 36px;',
            '  border-radius: 6px;',
            '}',
            '@media (max-width: 420px) {',
            '  .pwa-banner__text span { display: none; }',
            '}',
        ].join('\n');
        document.head.appendChild(style);
    }
})();
