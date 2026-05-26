/*
 * IntersectionObserver helper facade
 */
(function () {
    'use strict';

    if (window.__rhIntersectionObserverLoaded) return;
    window.__rhIntersectionObserverLoaded = true;

    const supported = 'IntersectionObserver' in window;

    function observeOnce(elements, callback, options) {
        const nodes = Array.isArray(elements) ? elements : Array.from(elements || []);
        if (!nodes.length || typeof callback !== 'function') return;

        if (!supported) {
            nodes.forEach((node) => callback(node));
            return;
        }

        const observer = new IntersectionObserver((entries, io) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;
                callback(entry.target);
                io.unobserve(entry.target);
            });
        }, options || { threshold: 0.1, rootMargin: '0px 0px -40px 0px' });

        nodes.forEach((node) => observer.observe(node));
    }

    window.RHIntersection = window.RHIntersection || {
        supported,
        observeOnce
    };
})();

