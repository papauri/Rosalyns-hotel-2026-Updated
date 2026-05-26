/*
 * Unified scroll reveal fallback hooks
 */
(function () {
    'use strict';

    if (window.__rhScrollRevealLoaded) return;
    window.__rhScrollRevealLoaded = true;

    const revealSelector = [
        '[data-scroll-animation]',
        '.scroll-reveal',
        '.fade-in-up',
        '.reveal-on-scroll'
    ].join(',');

    function applyReveal(el) {
        el.classList.add('revealed', 'is-revealed');
    }

    function init(root) {
        const nodes = root.querySelectorAll(revealSelector);
        if (!nodes.length) return;

        const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reduced || !('IntersectionObserver' in window)) {
            nodes.forEach(applyReveal);
            return;
        }

        const observer = new IntersectionObserver((entries, io) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                applyReveal(entry.target);
                io.unobserve(entry.target);
            });
        }, {
            threshold: 0.12,
            rootMargin: '0px 0px -60px 0px'
        });

        nodes.forEach((node) => observer.observe(node));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init(document), { once: true });
    } else {
        init(document);
    }

    window.addEventListener('spa:contentLoaded', () => init(document));
})();

