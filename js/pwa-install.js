/**
 * pwa-install.js — PWA install-prompt handler for Rosalyn's Hotel public website.
 *
 * Shows a draggable floating card when the browser fires `beforeinstallprompt`.
 * Position is persisted in localStorage so it stays where the user leaves it.
 */
(function () {
    'use strict';

    // ── Service Worker registration ───────────────────────────────────────────
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('/public-sw.js', { scope: '/' })
            .catch(function () { /* silent */ });
    }

    // ── Dismiss state ─────────────────────────────────────────────────────────
    const DISMISS_KEY  = 'pwa_install_dismissed';
    const POS_KEY      = 'pwa_banner_pos';
    const DISMISS_DAYS = 30;

    function isDismissed() {
        try {
            const ts = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            return ts > 0 && Date.now() - ts < DISMISS_DAYS * 86400 * 1000;
        } catch (e) { return false; }
    }

    function markDismissed() {
        try { localStorage.setItem(DISMISS_KEY, String(Date.now())); } catch (e) {}
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

    // ── Banner HTML ───────────────────────────────────────────────────────────
    function showBanner() {
        if (bannerEl) return;

        bannerEl = document.createElement('div');
        bannerEl.id = 'pwa-install-banner';
        bannerEl.setAttribute('role', 'region');
        bannerEl.setAttribute('aria-label', 'Install app');

        const siteName = window._siteName || "Rosalyn's Hotel";

        bannerEl.innerHTML = [
            '<div class="pwa-card__handle" id="pwa-drag-handle">',
            '  <span></span><span></span><span></span>',
            '</div>',
            '<button class="pwa-card__close" id="pwa-dismiss-btn" type="button" aria-label="Dismiss">',
            '  <i class="fas fa-times" aria-hidden="true"></i>',
            '</button>',
            '<div class="pwa-card__body">',
            '  <div class="pwa-card__icon"><i class="fas fa-hotel" aria-hidden="true"></i></div>',
            '  <div class="pwa-card__text">',
            '    <strong>' + siteName + '</strong>',
            '    <span>Install for quick access &amp; offline support</span>',
            '  </div>',
            '</div>',
            '<button class="pwa-card__install" id="pwa-install-btn" type="button">',
            '  <i class="fas fa-download" aria-hidden="true"></i> Install App',
            '</button>',
        ].join('');

        injectStyles();
        document.body.appendChild(bannerEl);

        restorePosition(bannerEl);
        makeDraggable(bannerEl);

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

    function dismiss() { markDismissed(); hideBanner(); }

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
                const maxL = Math.max(0, window.innerWidth  - 300);
                const maxT = Math.max(0, window.innerHeight - 200);
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
            '#pwa-install-banner {',
            '  position: fixed;',
            '  bottom: 24px;',
            '  right: 20px;',
            '  left: auto;',
            '  width: 280px;',
            '  max-width: calc(100vw - 32px);',
            '  z-index: 9000;',
            '  border-radius: 18px;',
            '  background: #231F1C;',
            '  color: #F7F3EE;',
            '  box-shadow: 0 8px 32px rgba(0,0,0,0.38), 0 2px 8px rgba(0,0,0,0.22);',
            '  font-family: "Jost", sans-serif;',
            '  cursor: grab;',
            '  user-select: none;',
            '  -webkit-user-select: none;',
            '  touch-action: none;',
            '  opacity: 0;',
            '  transform: scale(0.88) translateY(12px);',
            '  transition: opacity 0.32s cubic-bezier(0.4,0,0.2,1), transform 0.32s cubic-bezier(0.4,0,0.2,1);',
            '  overflow: hidden;',
            '}',
            '#pwa-install-banner.is-visible {',
            '  opacity: 1;',
            '  transform: scale(1) translateY(0);',
            '}',
            '#pwa-install-banner.is-dragging {',
            '  cursor: grabbing;',
            '  transition: none;',
            '  box-shadow: 0 20px 56px rgba(0,0,0,0.5), 0 4px 12px rgba(0,0,0,0.3);',
            '  transform: scale(1.03);',
            '}',

            /* Drag handle dots */
            '.pwa-card__handle {',
            '  display: flex;',
            '  justify-content: center;',
            '  gap: 4px;',
            '  padding: 10px 0 4px;',
            '}',
            '.pwa-card__handle span {',
            '  display: block;',
            '  width: 4px; height: 4px;',
            '  border-radius: 50%;',
            '  background: rgba(247,243,238,0.28);',
            '}',

            /* Close button */
            '.pwa-card__close {',
            '  position: absolute;',
            '  top: 10px; right: 12px;',
            '  background: rgba(247,243,238,0.1);',
            '  border: none;',
            '  color: rgba(247,243,238,0.55);',
            '  font-size: 13px;',
            '  width: 28px; height: 28px;',
            '  border-radius: 50%;',
            '  cursor: pointer;',
            '  display: flex; align-items: center; justify-content: center;',
            '  transition: background 0.15s, color 0.15s;',
            '}',
            '.pwa-card__close:hover {',
            '  background: rgba(247,243,238,0.18);',
            '  color: #F7F3EE;',
            '}',

            /* Card body */
            '.pwa-card__body {',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 12px;',
            '  padding: 6px 16px 14px;',
            '}',
            '.pwa-card__icon {',
            '  flex-shrink: 0;',
            '  width: 44px; height: 44px;',
            '  border-radius: 12px;',
            '  background: #8A775F;',
            '  display: flex; align-items: center; justify-content: center;',
            '  font-size: 20px; color: #fff;',
            '}',
            '.pwa-card__text {',
            '  flex: 1;',
            '  min-width: 0;',
            '  display: flex;',
            '  flex-direction: column;',
            '  gap: 3px;',
            '}',
            '.pwa-card__text strong {',
            '  font-size: 14px;',
            '  font-weight: 700;',
            '  color: #F7F3EE;',
            '  white-space: nowrap;',
            '  overflow: hidden;',
            '  text-overflow: ellipsis;',
            '}',
            '.pwa-card__text span {',
            '  font-size: 11px;',
            '  color: rgba(247,243,238,0.55);',
            '  line-height: 1.35;',
            '}',

            /* Install button */
            '.pwa-card__install {',
            '  display: flex;',
            '  align-items: center;',
            '  justify-content: center;',
            '  gap: 8px;',
            '  width: calc(100% - 32px);',
            '  margin: 0 16px 16px;',
            '  padding: 11px;',
            '  background: #8A775F;',
            '  color: #fff;',
            '  border: none;',
            '  border-radius: 10px;',
            '  font-size: 14px;',
            '  font-weight: 700;',
            '  font-family: inherit;',
            '  cursor: pointer;',
            '  transition: background 0.15s;',
            '}',
            '.pwa-card__install:hover { background: #7a6852; }',
        ].join('\n');
        document.head.appendChild(s);
    }
})();
