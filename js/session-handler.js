/*
 * Session handler (safe no-op baseline)
 * Keeps lightweight per-tab state without affecting navigation/transition systems.
 */
(function () {
    'use strict';

    if (window.__rhSessionHandlerLoaded) return;
    window.__rhSessionHandlerLoaded = true;

    function safeSet(key, value) {
        try {
            sessionStorage.setItem(key, value);
        } catch (_) {
            // Storage may be unavailable (private mode / restrictions)
        }
    }

    function safeGet(key) {
        try {
            return sessionStorage.getItem(key);
        } catch (_) {
            return null;
        }
    }

    function updateSessionMeta() {
        safeSet('rh:lastPath', window.location.pathname + window.location.search + window.location.hash);
        safeSet('rh:lastSeenAt', String(Date.now()));
    }

    updateSessionMeta();
    window.addEventListener('page:transition:end', updateSessionMeta);
    window.addEventListener('spa:contentLoaded', updateSessionMeta);

    window.RHSession = window.RHSession || {
        get: safeGet,
        set: safeSet
    };
})();

