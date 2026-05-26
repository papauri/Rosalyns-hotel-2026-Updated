/**
 * Spatial Loading Enhancements (Restaurant page)
 *
 * Purpose:
 * - Resolve missing script reference (404) on restaurant page
 * - Add lightweight progressive enhancement for menu tabs/cards
 * - Remain compatible with unified navigation and page transitions
 */
(function () {
    'use strict';

    const SELECTORS = {
        wrapper: '#menuCategoriesWrapper',
        tabs: '#menuTabs',
        content: '#menuContent',
        panel: '.menu-panel',
        card: '.menu-item'
    };

    let observer = null;

    function getContext() {
        return {
            wrapper: document.querySelector(SELECTORS.wrapper),
            tabsRoot: document.querySelector(SELECTORS.tabs),
            contentRoot: document.querySelector(SELECTORS.content)
        };
    }

    function enhanceTabKeyboardNavigation(tabsRoot) {
        if (!tabsRoot || tabsRoot.dataset.spatialTabsBound === '1') return;

        tabsRoot.addEventListener('keydown', (event) => {
            const tabs = Array.from(tabsRoot.querySelectorAll('.menu-tab'));
            if (!tabs.length) return;

            const currentIndex = tabs.findIndex((tab) => tab.classList.contains('active'));
            if (currentIndex === -1) return;

            let targetIndex = currentIndex;

            if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                targetIndex = (currentIndex + 1) % tabs.length;
            } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                targetIndex = (currentIndex - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                targetIndex = 0;
            } else if (event.key === 'End') {
                targetIndex = tabs.length - 1;
            } else {
                return;
            }

            event.preventDefault();
            const target = tabs[targetIndex];
            target.focus();
            target.click();
        });

        tabsRoot.dataset.spatialTabsBound = '1';
    }

    function revealCardsInViewport(contentRoot) {
        if (!contentRoot) return;

        const cards = contentRoot.querySelectorAll(SELECTORS.card);
        if (!cards.length) return;

        // Respect reduced motion preferences
        const reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        if (reducedMotion) {
            cards.forEach((card) => {
                card.style.opacity = '1';
                card.style.transform = 'none';
            });
            return;
        }

        // Reset existing observer before creating a new one
        if (observer) {
            observer.disconnect();
        }

        observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (!entry.isIntersecting) return;

                const card = entry.target;
                card.style.opacity = '1';
                card.style.transform = 'translateY(0)';
                observer.unobserve(card);
            });
        }, {
            threshold: 0.15,
            rootMargin: '0px 0px -30px 0px'
        });

        cards.forEach((card, index) => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(10px)';
            card.style.transition = `opacity 320ms ease ${Math.min(index * 18, 220)}ms, transform 320ms ease ${Math.min(index * 18, 220)}ms`;
            observer.observe(card);
        });
    }

    function observeMenuMutations(contentRoot) {
        if (!contentRoot || contentRoot.dataset.spatialObserved === '1') return;

        const mutationObserver = new MutationObserver(() => {
            revealCardsInViewport(contentRoot);
        });

        mutationObserver.observe(contentRoot, { childList: true, subtree: true });
        contentRoot.dataset.spatialObserved = '1';
    }

    function init() {
        const { wrapper, tabsRoot, contentRoot } = getContext();
        if (!wrapper || !tabsRoot || !contentRoot) return;

        enhanceTabKeyboardNavigation(tabsRoot);
        observeMenuMutations(contentRoot);
        revealCardsInViewport(contentRoot);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Reinitialize after SPA content replacement / transition completion
    window.addEventListener('spa:contentLoaded', init);
    window.addEventListener('page:navigation:end', init);
})();

