/**
 * kiosk-fullscreen.js
 * Opt-in fullscreen helper for kiosk pages.
 *
 * Exposes window.KioskFullscreen with methods:
 *   - isActive(): boolean
 *   - enter(): Promise<boolean>
 *   - exit(): Promise<boolean>
 *   - toggle(): Promise<boolean>
 */
(function () {
    'use strict';

    const LS_KEY = 'rh_kiosk_fs_pref';

    function isFs() {
        return !!(
            document.fullscreenElement ||
            document.webkitFullscreenElement ||
            document.mozFullScreenElement ||
            document.msFullscreenElement
        );
    }

    function requestFs() {
        const el = document.documentElement;
        const fn =
            el.requestFullscreen ||
            el.webkitRequestFullscreen ||
            el.mozRequestFullScreen ||
            el.msRequestFullscreen;
        if (!fn) return Promise.resolve(false);
        return Promise.resolve(fn.call(el, { navigationUI: 'hide' }))
            .then(() => {
                try {
                    localStorage.setItem(LS_KEY, '1');
                } catch (_e) {
                    // Ignore storage failures.
                }
                return true;
            })
            .catch(() => false);
    }

    function exitFs() {
        const fn =
            document.exitFullscreen ||
            document.webkitExitFullscreen ||
            document.mozCancelFullScreen ||
            document.msExitFullscreen;
        if (!fn) return Promise.resolve(false);
        return Promise.resolve(fn.call(document))
            .then(() => {
                try {
                    localStorage.setItem(LS_KEY, '0');
                } catch (_e) {
                    // Ignore storage failures.
                }
                return true;
            })
            .catch(() => false);
    }

    function toggleFs() {
        if (isFs()) return exitFs();
        return requestFs();
    }

    window.KioskFullscreen = {
        isActive: isFs,
        enter: requestFs,
        exit: exitFs,
        toggle: toggleFs,
    };

    // Optional compatibility: auto re-enter only if explicitly opted-in elsewhere.
    function shouldAutoReenter() {
        try {
            return localStorage.getItem(LS_KEY) === '1' && document.body?.dataset.kioskAutoFullscreen === '1';
        } catch (_e) {
            return false;
        }
    }

    document.addEventListener('fullscreenchange', function () {
        if (!isFs() && shouldAutoReenter()) {
            // Re-enter only when an explicit kiosk auto mode is enabled.
            requestFs();
        }
    });
})();
