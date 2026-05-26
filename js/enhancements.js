/*
 * Progressive enhancements bootstrap (safe no-op if unsupported)
 */
(function () {
    'use strict';

    if (window.__rhEnhancementsLoaded) return;
    window.__rhEnhancementsLoaded = true;

    const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    function markEnhancedRoot() {
        document.documentElement.classList.add('js-enhanced');
        if (prefersReducedMotion) {
            document.documentElement.classList.add('reduced-motion');
        }
    }

    function init() {
        markEnhancedRoot();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }

    window.addEventListener('spa:contentLoaded', markEnhancedRoot);
})();

