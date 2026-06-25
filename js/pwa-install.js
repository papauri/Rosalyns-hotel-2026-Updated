/**
 * pwa-install.js — PWA install-prompt handler for Rosalyn's Hotel public website.
 *
 * Shows a compact floating pill when the browser fires `beforeinstallprompt`.
 * Includes macOS-style minimize animation that scales the banner down to a small
 * floating icon in the bottom-right corner. Position is persisted in localStorage.
 */
(function () {
    'use strict';

    // ── Service Worker registration ───────────────────────────────────────────
    // Use a relative path so it resolves correctly in any subdirectory install.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('public-sw.js', { scope: './' })
            .catch(function () { /* silent */ });
    }

    // ── Dismiss state ─────────────────────────────────────────────────────────
    const DISMISS_KEY    = 'pwa_install_dismissed';
    const POS_KEY        = 'pwa_banner_pos';
    const MINIMIZED_KEY  = 'pwa_banner_minimized';
    const DISMISS_DAYS   = 30;

    function isDismissed() {
        try {
            const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            return ts > 0 && Date.now() - ts < DISMISS_DAYS * 86400 * 1000;
        } catch (e) { return false; }
    }

    function markDismissed() {
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
    }

    function isMinimized() {
        try { return localStorage.getItem(MINIMIZED_KEY) === 'true'; } catch (e) { return false; }
    }

    function markMinimized(state) {
        try { localStorage.setItem(MINIMIZED_KEY, state ? 'true' : 'false'); } catch (e) {}
    }

    let deferredPrompt = null;
    let bannerEl = null;
    let isMinimisedState = false;

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

    // ── Banner HTML ───────────────────────────────────────────────────────────
    function showBanner() {
        if (bannerEl) return;

        bannerEl = document.createElement('div');
        bannerEl.id = 'pwa-install-banner';
        bannerEl.setAttribute('role', 'region');
        bannerEl.setAttribute('aria-label', 'Install app');

        const siteName = window._siteName || "Rosalyn's Hotel";

        bannerEl.innerHTML = [
            '<div class="pwa-banner__content">',
            '  <div class="pwa-banner__icon" aria-hidden="true">',
            '    <i class="fas fa-hotel"></i>',
            '  </div>',
            '  <div class="pwa-banner__text">',
            '    <strong>' + siteName + '</strong>',
            '    <span>Install for quick access &amp; offline support</span>',
            '  </div>',
            '  <button class="pwa-banner__install" id="pwa-install-btn" type="button" aria-label="Install app">',
            '    <i class="fas fa-download" aria-hidden="true"></i>',
            '  </button>',
            '</div>',
            '<div class="pwa-banner__actions">',
            '  <button class="pwa-banner__minimize" id="pwa-minimize-btn" type="button" aria-label="Minimize" title="Minimize">',
            '    <i class="fas fa-minus" aria-hidden="true"></i>',
            '  </button>',
            '  <button class="pwa-banner__close" id="pwa-dismiss-btn" type="button" aria-label="Dismiss" title="Dismiss">',
            '    <i class="fas fa-times" aria-hidden="true"></i>',
            '  </button>',
            '</div>',
        ].join('');

        injectStyles();
        document.body.appendChild(bannerEl);

        restorePosition(bannerEl);
        makeDraggable(bannerEl);

        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                bannerEl.classList.add('is-visible');
                if (isMinimized()) toggleMinimize();
            });
        });

        document.getElementById('pwa-install-btn').addEventListener('click', triggerInstall);
        document.getElementById('pwa-dismiss-btn').addEventListener('click', dismiss);
        document.getElementById('pwa-minimize-btn').addEventListener('click', toggleMinimize);
    }

    function hideBanner() {
        if (!bannerEl) return;
        bannerEl.classList.remove('is-visible');
        setTimeout(function () {
            if (bannerEl && bannerEl.parentNode) bannerEl.parentNode.removeChild(bannerEl);
            bannerEl = null;
        }, 380);
    }

    function dismiss() { markDismissed(); hideBanner(); }

    function toggleMinimize() {
        if (!bannerEl) return;
        isMinimisedState = !isMinimisedState;
        markMinimized(isMinimisedState);
        bannerEl.classList.toggle('is-minimised', isMinimisedState);
        const minBtn = document.getElementById('pwa-minimize-btn');
        if (minBtn) {
            minBtn.setAttribute('aria-label', isMinimisedState ? 'Expand' : 'Minimize');
            minBtn.setAttribute('title', isMinimisedState ? 'Expand' : 'Minimize');
            minBtn.querySelector('i').className = isMinimisedState ? 'fas fa-plus' : 'fas fa-minus';
        }
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

    // ── Drag-to-move ──────────────────────────────────────────────────────────
    function restorePosition(el) {
        try {
            const saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null');
            if (saved && typeof saved.left === 'number' && typeof saved.top === 'number') {
                // Clamp to viewport in case screen size changed
                // Minimum sizes: expanded ~280px, minimized ~56px
                const minW = isMinimized() ? 56 : 280;
                const minH = isMinimized() ? 56 : 120;
                const maxL = Math.max(0, window.innerWidth  - minW);
                const maxT = Math.max(0, window.innerHeight - minH);
                el.style.left   = Math.min(saved.left, maxL) + 'px';
                el.style.top    = Math.min(saved.top,  maxT) + 'px';
                el.style.bottom = 'auto';
                el.style.right  = 'auto';
            }
        } catch (e) {}
    }

    function savePosition(el) {
        try {
            localStorage.setItem(POS_KEY, JSON.stringify({
                left: parseFloat(el.style.left) || 0,
                top:  parseFloat(el.style.top)  || 0,
            }));
        } catch (e) {}
    }

    function makeDraggable(el) {
        let dragging = false;
        let startX, startY, startLeft, startTop;

        function beginDrag(clientX, clientY) {
            const rect = el.getBoundingClientRect();
            startX    = clientX;
            startY    = clientY;
            startLeft = rect.left;
            startTop  = rect.top;
            // Pin position explicitly so we can drop bottom/right
            el.style.left   = startLeft + 'px';
            el.style.top    = startTop  + 'px';
            el.style.bottom = 'auto';
            el.style.right  = 'auto';
            el.style.transition = 'none';
            el.classList.add('is-dragging');
            dragging = true;
        }

        function moveDrag(clientX, clientY) {
            if (!dragging) return;
            const dx     = clientX - startX;
            const dy     = clientY - startY;
            const newL   = Math.max(0, Math.min(window.innerWidth  - el.offsetWidth,  startLeft + dx));
            const newT   = Math.max(0, Math.min(window.innerHeight - el.offsetHeight, startTop  + dy));
            el.style.left = newL + 'px';
            el.style.top  = newT + 'px';
        }

        function endDrag() {
            if (!dragging) return;
            dragging = false;
            el.classList.remove('is-dragging');
            el.style.transition = '';
            savePosition(el);
        }

        // Mouse drag — whole card except buttons
        el.addEventListener('mousedown', function (e) {
            if (e.target.closest('button')) return;
            e.preventDefault();
            beginDrag(e.clientX, e.clientY);
        });
        document.addEventListener('mousemove', function (e) {
            if (dragging) moveDrag(e.clientX, e.clientY);
        });
        document.addEventListener('mouseup', endDrag);

        // Touch drag
        el.addEventListener('touchstart', function (e) {
            if (e.target.closest('button')) return;
            beginDrag(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: true });
        document.addEventListener('touchmove', function (e) {
            if (!dragging) return;
            e.preventDefault();
            moveDrag(e.touches[0].clientX, e.touches[0].clientY);
        }, { passive: false });
        document.addEventListener('touchend', endDrag);
    }

    // ── Styles ────────────────────────────────────────────────────────────────
    function injectStyles() {
        if (document.getElementById('pwa-install-styles')) return;
        const s = document.createElement('style');
        s.id = 'pwa-install-styles';
        s.textContent = [
            '/* PWA Install Banner — Compact Floating Pill Design with macOS Minimize Animation */',
            '',
            '#pwa-install-banner {',
            '  position: fixed;',
            '  bottom: 24px;',
            '  right: 20px;',
            '  left: auto;',
            '  top: auto;',
            '  width: auto;',
            '  z-index: 9000;',
            '  background: linear-gradient(135deg, #2C2825 0%, #3A3530 100%);',
            '  color: #FAF9F7;',
            '  border-radius: 16px;',
            '  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.35), 0 2px 8px rgba(0, 0, 0, 0.15);',
            '  font-family: "Jost", sans-serif;',
            '  cursor: grab;',
            '  user-select: none;',
            '  -webkit-user-select: none;',
            '  touch-action: none;',
            '  opacity: 0;',
            '  transform: scale(0.92) translateY(16px);',
            '  transform-origin: bottom right;',
            '  transition: opacity 0.35s cubic-bezier(0.34, 1.56, 0.64, 1), transform 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);',
            '  overflow: visible;',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 12px;',
            '  padding: 12px 14px;',
            '  max-width: calc(100vw - 32px);',
            '}',
            '',
            '#pwa-install-banner.is-visible {',
            '  opacity: 1;',
            '  transform: scale(1) translateY(0);',
            '}',
            '',
            '/* Minimized state: small floating circle with icon only */',
            '#pwa-install-banner.is-minimised {',
            '  width: 56px;',
            '  height: 56px;',
            '  padding: 0;',
            '  border-radius: 50%;',
            '  transform: scale(1) translateY(0);',
            '  justify-content: center;',
            '  align-items: center;',
            '}',
            '',
            '#pwa-install-banner.is-minimised .pwa-banner__content {',
            '  display: none;',
            '}',
            '',
            '#pwa-install-banner.is-minimised .pwa-banner__actions {',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  gap: 0;',
            '}',
            '',
            '#pwa-install-banner.is-minimised .pwa-banner__close {',
            '  display: none;',
            '}',
            '',
            '#pwa-install-banner.is-minimised .pwa-banner__minimize {',
            '  width: 100%;',
            '  height: 100%;',
            '  border-radius: 50%;',
            '  background: linear-gradient(135deg, #8B7355 0%, #6F5D47 100%);',
            '  padding: 0;',
            '  margin: 0;',
            '}',
            '',
            '#pwa-install-banner.is-dragging {',
            '  cursor: grabbing;',
            '  transition: none;',
            '  box-shadow: 0 20px 56px rgba(0, 0, 0, 0.5), 0 4px 12px rgba(0, 0, 0, 0.3);',
            '  transform: scale(1.05);',
            '}',
            '',
            '.pwa-banner__content {',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 10px;',
            '  flex: 1;',
            '  min-width: 0;',
            '  transition: opacity 0.3s ease;',
            '}',
            '',
            '.pwa-banner__icon {',
            '  flex-shrink: 0;',
            '  width: 40px;',
            '  height: 40px;',
            '  border-radius: 12px;',
            '  background: linear-gradient(135deg, #C9A96E 0%, #A68A5B 100%);',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  font-size: 18px;',
            '  color: #2C2825;',
            '  flex-shrink: 0;',
            '}',
            '',
            '.pwa-banner__text {',
            '  display: flex;',
            '  flex-direction: column;',
            '  gap: 2px;',
            '  flex: 1;',
            '  min-width: 0;',
            '}',
            '',
            '.pwa-banner__text strong {',
            '  font-size: 13px;',
            '  font-weight: 700;',
            '  color: #FAF9F7;',
            '  line-height: 1.2;',
            '  white-space: nowrap;',
            '  overflow: hidden;',
            '  text-overflow: ellipsis;',
            '}',
            '',
            '.pwa-banner__text span {',
            '  font-size: 11px;',
            '  color: rgba(250, 249, 247, 0.6);',
            '  line-height: 1.3;',
            '  white-space: normal;',
            '  display: -webkit-box;',
            '  -webkit-line-clamp: 1;',
            '  -webkit-box-orient: vertical;',
            '  overflow: hidden;',
            '}',
            '',
            '.pwa-banner__install {',
            '  flex-shrink: 0;',
            '  width: 40px;',
            '  height: 40px;',
            '  padding: 0;',
            '  background: #8B7355;',
            '  color: #FAF9F7;',
            '  border: none;',
            '  border-radius: 10px;',
            '  font-size: 16px;',
            '  cursor: pointer;',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  transition: all 0.2s cubic-bezier(0.34, 1.56, 0.64, 1);',
            '  box-shadow: 0 4px 12px rgba(139, 115, 85, 0.25);',
            '}',
            '',
            '.pwa-banner__install:hover {',
            '  background: #6F5D47;',
            '  box-shadow: 0 6px 16px rgba(139, 115, 85, 0.35);',
            '  transform: translateY(-2px);',
            '}',
            '',
            '.pwa-banner__install:active {',
            '  transform: translateY(0);',
            '}',
            '',
            '.pwa-banner__actions {',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 6px;',
            '  flex-shrink: 0;',
            '}',
            '',
            '.pwa-banner__minimize,',
            '.pwa-banner__close {',
            '  width: 32px;',
            '  height: 32px;',
            '  padding: 0;',
            '  background: transparent;',
            '  border: none;',
            '  color: rgba(250, 249, 247, 0.5);',
            '  font-size: 14px;',
            '  cursor: pointer;',
            '  border-radius: 8px;',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  transition: all 0.15s ease;',
            '}',
            '',
            '.pwa-banner__minimize:hover,',
            '.pwa-banner__close:hover {',
            '  color: #FAF9F7;',
            '  background: rgba(250, 249, 247, 0.08);',
            '}',
            '',
            '.pwa-banner__minimize:active,',
            '.pwa-banner__close:active {',
            '  transform: scale(0.92);',
            '}',
            '',
            '/* Responsive adjustments */',
            '@media (max-width: 480px) {',
            '  #pwa-install-banner {',
            '    right: 12px;',
            '    bottom: 12px;',
            '    padding: 10px 12px;',
            '    gap: 8px;',
            '  }',
            '  .pwa-banner__text strong {',
            '    font-size: 12px;',
            '  }',
            '  .pwa-banner__text span {',
            '    font-size: 10px;',
            '  }',
            '  .pwa-banner__icon {',
            '    width: 36px;',
            '    height: 36px;',
            '    font-size: 16px;',
            '  }',
            '  .pwa-banner__install {',
            '    width: 36px;',
            '    height: 36px;',
            '    font-size: 14px;',
            '  }',
            '}',
        ].join('\n');
        document.head.appendChild(s);
    }
})();
