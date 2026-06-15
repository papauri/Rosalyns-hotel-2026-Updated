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
            '<div class="admin-pwa-banner__drag-handle" id="admin-pwa-drag" title="Drag to move" aria-hidden="true">',
            '  <i class="fas fa-grip-lines"></i>',
            '</div>',
            '<i class="fas fa-hotel admin-pwa-banner__icon" aria-hidden="true"></i>',
            '<div class="admin-pwa-banner__text" id="admin-pwa-body">',
            '  <strong>Install Admin App</strong>',
            '  <span>Run POS &amp; KDS without a browser tab.</span>',
            '</div>',
            '<button class="admin-pwa-banner__install" id="admin-pwa-install" type="button">Install</button>',
            '<button class="admin-pwa-banner__minimise" id="admin-pwa-minimise" type="button" aria-label="Minimise">',
            '  <i class="fas fa-minus" aria-hidden="true"></i>',
            '</button>',
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
        document.getElementById('admin-pwa-minimise').addEventListener('click', toggleMinimise);

        initDrag(bannerEl);
    }

    var _minimised = false;
    function toggleMinimise() {
        _minimised = !_minimised;
        if (!bannerEl) return;
        bannerEl.classList.toggle('is-minimised', _minimised);
        var btn = document.getElementById('admin-pwa-minimise');
        if (btn) {
            btn.setAttribute('aria-label', _minimised ? 'Expand' : 'Minimise');
            btn.querySelector('i').className = _minimised ? 'fas fa-plus' : 'fas fa-minus';
        }
    }

    function initDrag(el, handle) {
        // Make the whole banner draggable (not just the tiny handle) —
        // any pointerdown that isn't on a button starts a drag.
        var startX, startY, startLeft, startTop, dragging = false, moved = false;

        function onPointerDown(e) {
            if (e.target.closest('button')) return; // don't intercept button taps
            dragging = true;
            moved = false;
            var rect = el.getBoundingClientRect();
            startLeft = rect.left;
            startTop  = rect.top;
            startX = e.clientX;
            startY = e.clientY;
            el.style.transition = 'none';
            el.style.right  = 'auto';
            el.style.bottom = 'auto'; // clear media-query bottom so top: works on mobile
            el.style.left   = startLeft + 'px';
            el.style.top    = startTop  + 'px';
            el.setPointerCapture(e.pointerId);
            e.preventDefault();
        }

        function onPointerMove(e) {
            if (!dragging) return;
            var dx = e.clientX - startX;
            var dy = e.clientY - startY;
            if (!moved && Math.abs(dx) < 4 && Math.abs(dy) < 4) return; // dead zone
            moved = true;
            var newLeft = Math.max(0, Math.min(window.innerWidth  - el.offsetWidth,  startLeft + dx));
            var newTop  = Math.max(0, Math.min(window.innerHeight - el.offsetHeight, startTop  + dy));
            el.style.left = newLeft + 'px';
            el.style.top  = newTop  + 'px';
        }

        function onPointerUp() {
            if (!moved) { /* treat as tap — nothing extra needed */ }
            dragging = false;
            moved = false;
            el.style.transition = '';
        }

        el.addEventListener('pointerdown',  onPointerDown);
        el.addEventListener('pointermove',  onPointerMove);
        el.addEventListener('pointerup',    onPointerUp);
        el.addEventListener('pointercancel', onPointerUp);
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
            '  top: 20px; right: 20px;',
            '  z-index: 9100;',
            '  display: flex;',
            '  align-items: center;',
            '  gap: 10px;',
            '  background: #1f1f24;',
            '  border: 1px solid rgba(138,119,95,0.4);',
            '  color: #F7F3EE;',
            '  padding: 10px 12px 10px 8px;',
            '  border-radius: 12px;',
            '  box-shadow: 0 8px 32px rgba(0,0,0,0.5);',
            '  max-width: 360px;',
            '  transform: translateY(-20px);',
            '  opacity: 0;',
            '  transition: transform 0.3s ease, opacity 0.3s ease;',
            '  font-family: "Inter", system-ui, sans-serif;',
            '  font-size: 13px;',
            '  touch-action: none;',
            '}',
            '#admin-pwa-banner.is-visible { transform: translateY(0); opacity: 1; }',
            '#admin-pwa-banner.is-minimised .admin-pwa-banner__text,',
            '#admin-pwa-banner.is-minimised .admin-pwa-banner__install { display: none; }',
            '#admin-pwa-banner.is-minimised { gap: 6px; }',
            '.admin-pwa-banner__drag-handle {',
            '  flex-shrink: 0;',
            '  color: rgba(247,243,238,0.25);',
            '  font-size: 11px;',
            '  padding: 4px 6px;',
            '  cursor: grab;',
            '  line-height: 1;',
            '  touch-action: none;',
            '  -webkit-user-select: none; user-select: none;',
            '}',
            '.admin-pwa-banner__drag-handle:active { cursor: grabbing; }',
            '.admin-pwa-banner__icon {',
            '  flex-shrink: 0;',
            '  font-size: 18px;',
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
            '.admin-pwa-banner__minimise, .admin-pwa-banner__dismiss {',
            '  flex-shrink: 0;',
            '  background: transparent;',
            '  border: none;',
            '  color: rgba(247,243,238,0.35);',
            '  font-size: 12px;',
            '  padding: 6px 5px;',
            '  cursor: pointer;',
            '  border-radius: 5px;',
            '  transition: color 0.15s;',
            '  line-height: 1;',
            '}',
            '.admin-pwa-banner__minimise:hover, .admin-pwa-banner__dismiss:hover { color: rgba(247,243,238,0.85); }',
            '@media (max-width: 480px) {',
            '  #admin-pwa-banner { top: auto; bottom: 80px; right: 12px; left: auto; max-width: calc(100vw - 24px); padding: 12px 10px; }',
            '  .admin-pwa-banner__minimise, .admin-pwa-banner__dismiss { padding: 10px 8px; font-size: 14px; }',
            '  .admin-pwa-banner__drag-handle { padding: 6px 8px; font-size: 14px; }',
            '}',
        ].join('\n');
        document.head.appendChild(s);
    }
})();
