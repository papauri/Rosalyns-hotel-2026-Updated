/*
 * Editorial rooms animation hooks
 */
(function () {
    'use strict';

    if (window.__rhEditorialRoomsAnimationsLoaded) return;
    window.__rhEditorialRoomsAnimationsLoaded = true;

    const selectors = [
        '.editorial-rooms-section [data-animation]',
        '.editorial-rooms-row > *',
        '.editorial-room-card'
    ];

    function reveal(el) {
        el.classList.add('is-revealed', 'a1');
    }

    function init(root) {
        const nodes = root.querySelectorAll(selectors.join(','));
        if (!nodes.length) return;

        if (!('IntersectionObserver' in window)) {
            nodes.forEach(reveal);
            return;
        }

        const io = new IntersectionObserver((entries, observer) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                reveal(entry.target);
                observer.unobserve(entry.target);
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });

        nodes.forEach((el) => io.observe(el));
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init(document), { once: true });
    } else {
        init(document);
    }

    window.addEventListener('spa:contentLoaded', () => init(document));
})();

