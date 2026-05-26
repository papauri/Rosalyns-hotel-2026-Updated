/*
 * Lightweight parallax cards (reduced-motion aware)
 */
(function () {
    'use strict';

    if (window.__rhParallaxCardsLoaded) return;
    window.__rhParallaxCardsLoaded = true;

    const prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    const selectors = [
        '.editorial-room-card',
        '.room-card',
        '.room-tile',
        '[data-parallax-card]'
    ];

    function initParallax(root) {
        const nodes = root.querySelectorAll(selectors.join(','));
        if (!nodes.length) return;

        nodes.forEach((el) => {
            if (el.dataset.parallaxBound === '1') return;
            el.dataset.parallaxBound = '1';

            el.addEventListener('mousemove', (e) => {
                const rect = el.getBoundingClientRect();
                const x = (e.clientX - rect.left) / Math.max(rect.width, 1) - 0.5;
                const y = (e.clientY - rect.top) / Math.max(rect.height, 1) - 0.5;
                const rx = (y * -4).toFixed(2);
                const ry = (x * 6).toFixed(2);
                el.style.transform = `perspective(900px) rotateX(${rx}deg) rotateY(${ry}deg)`;
            }, { passive: true });

            el.addEventListener('mouseleave', () => {
                el.style.transform = '';
            }, { passive: true });
        });
    }

    function boot() {
        initParallax(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

    window.addEventListener('spa:contentLoaded', () => initParallax(document));
})();

