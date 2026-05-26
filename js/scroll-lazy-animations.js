/**
 * Scroll Reveal Animations — Rosalyn's Hotel 2026
 *
 * Fancy, staggered section + card reveal on desktop (≥1024px).
 * Simple opacity fade on mobile/tablet.
 * Fully respects prefers-reduced-motion.
 * Works with SPA navigation via the "spa:contentLoaded" event.
 */
(function () {
    'use strict';

    if (window.__rhScrollLazyAnimationsLoaded) return;
    window.__rhScrollLazyAnimationsLoaded = true;

    /* =========================================================================
       CSS — injected once into <head>
    ========================================================================= */

    function injectStyles() {
        if (document.getElementById('rh-scroll-reveal-css')) return;

        const css = `
/* ── Rosalyn Hotel Scroll Reveal — Premium Cinematic System ───────── */

/* Base hidden state — subtle lift, ready to reveal */
.rh-reveal {
    opacity: 0;
    transform: translateY(28px);
    will-change: opacity, transform;
    transition:
        opacity  0.82s cubic-bezier(0.16, 1, 0.3, 1),
        transform 0.82s cubic-bezier(0.16, 1, 0.3, 1);
}

/* Revealed — fully visible, transform reset */
.rh-reveal.is-revealed {
    opacity: 1 !important;
    transform: none !important;
    will-change: auto;
}

/* ── Desktop cinematic variants (≥1024px, no reduced-motion) ───── */
@media (min-width: 1024px) and (prefers-reduced-motion: no-preference) {

    /* Section-level: longer travel, cinema-grade ease */
    .rh-reveal--up {
        transform: translateY(72px);
        transition-duration: 0.95s;
        transition-timing-function: cubic-bezier(0.12, 0.96, 0.24, 1);
    }

    /* Card / gallery items: moderate lift */
    .rh-reveal--up-sm {
        transform: translateY(38px);
        transition-duration: 0.78s;
        transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
    }

    /* Feature / icon cards: slight scale + lift for depth */
    .rh-reveal--scale {
        transform: scale(0.91) translateY(30px);
        transform-origin: center bottom;
        transition-duration: 0.8s;
        transition-timing-function: cubic-bezier(0.16, 1, 0.3, 1);
    }

    /* Pure opacity fade — for already-positioned elements */
    .rh-reveal--fade {
        transform: none;
        transition-duration: 0.7s;
        transition-timing-function: ease-out;
    }

    /* Decorative lines sweep in from left */
    .rh-reveal--line {
        transform: scaleX(0);
        transform-origin: left center;
        transition-duration: 0.68s;
        transition-timing-function: cubic-bezier(0.22, 1, 0.36, 1);
    }

    /* Stagger children via custom property */
    .rh-stagger-item {
        transition-delay: var(--rh-stagger-delay, 0ms);
    }

    /* Section-level overrides — longer duration */
    section.rh-reveal,
    .section-padding.rh-reveal {
        transition-duration: 1.05s;
        transition-timing-function: cubic-bezier(0.12, 0.96, 0.24, 1);
    }

    /* Editorial cards get a slightly snappier reveal */
    .editorial-event-card.rh-reveal,
    .card.room-card.rh-reveal,
    .editorial-room-card.rh-reveal {
        transition-duration: 0.72s;
        transition-timing-function: cubic-bezier(0.22, 1, 0.36, 1);
    }
}

/* ── Tablet — gentler motion ────────────────────────────────────── */
@media (min-width: 768px) and (max-width: 1023px) and (prefers-reduced-motion: no-preference) {
    .rh-reveal {
        transform: translateY(22px);
        transition:
            opacity  0.66s cubic-bezier(0.16, 1, 0.3, 1),
            transform 0.66s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .rh-reveal.is-revealed {
        transform: none !important;
    }
}

/* ── Mobile — minimal, fast fade ───────────────────────────────── */
@media (max-width: 767px) and (prefers-reduced-motion: no-preference) {
    .rh-reveal {
        transform: translateY(16px);
        transition:
            opacity  0.52s cubic-bezier(0.16, 1, 0.3, 1),
            transform 0.52s cubic-bezier(0.16, 1, 0.3, 1);
    }
    .rh-reveal.is-revealed {
        transform: none !important;
    }
}

/* ── Reduced motion — instant opacity only ──────────────────────── */
@media (prefers-reduced-motion: reduce) {
    .rh-reveal {
        transform: none !important;
        transition: opacity 0.28s ease !important;
    }
    .rh-stagger-item {
        transition-delay: 0ms !important;
    }
}

/* ── End Rosalyn Hotel Scroll Reveal ────────────────────────────── */
        `.trim();

        const style   = document.createElement('style');
        style.id      = 'rh-scroll-reveal-css';
        style.textContent = css;
        document.head.appendChild(style);
    }

    /* =========================================================================
       Environment helpers
     ========================================================================= */

    const isDesktop        = () => window.matchMedia('(min-width: 1024px)').matches;
    const reducedMotion    = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // Simple viewport helpers for reset logic
    function isLikelyAboveFold(el) {
        if (!el || !el.getBoundingClientRect) return false;
        const rect = el.getBoundingClientRect();
        const vh   = window.innerHeight || document.documentElement.clientHeight || 0;
        // Treat top 85% of viewport as revealable; allow slight negative top for overscan
        const buffer = 0.85;
        const topCut = -120; // allow a small negative to include just-entered elements
        return rect.top < (vh * buffer) && rect.bottom > topCut;
    }

    /* =========================================================================
       Reset any pre-revealed markup on desktop (to avoid everything loaded)
     ========================================================================= */
    function resetPreRevealed(root) {
        if (!isDesktop() || reducedMotion()) return;

        // 1) Scroll-lazy system: elements using .rh-reveal
        root.querySelectorAll('.rh-reveal.is-revealed').forEach(el => {
            if (el.closest('.hero, [class*="hero--"]')) return;
            if (!isLikelyAboveFold(el)) {
                el.classList.remove('is-revealed');
            }
        });

        // 2) Page-transitions system: elements that may be pre-marked as revealed
        const PT_SELECTORS = [
            '.reveal-on-scroll',
            '.scroll-reveal',
            '.fade-in-up',
            '.editorial-room-card',
            '.editorial-facility-card',
            '.editorial-testimonial-card',
            '.room-card',
            '.facility-card',
            '.testimonial-card',
            '.room-tile'
        ].join(', ');

        root.querySelectorAll(PT_SELECTORS).forEach(el => {
            if (el.closest('.hero, [class*="hero--"]')) return;
            if (!isLikelyAboveFold(el)) {
                el.classList.remove('revealed', 'lakeside-visible', 'scroll-animated', 'scroll-animate-init');
                // Clear inline reveal styles; the owning system will set initial state again
                el.style.opacity = '';
                el.style.transform = '';
            }
        });
    }

    /* =========================================================================
       Selectors
    ========================================================================= */

    // Elements that reveal as a whole (section-level)
    const SECTION_SEL = [
        'section:not(.hero):not([class*="hero--"])',
        '.section-padding:not(.hero)',
        '[data-lazy-reveal]',
        '.reveal-on-scroll',
        '.scroll-reveal',
    ].join(', ');

    // Grid / list containers whose CHILDREN should be staggered
    const STAGGER_CONTAINERS = [
        '.editorial-gallery-grid',
        '.editorial-experience-grid',
        '.rooms-grid',
        '.menu-items-grid',
        '.features-grid',
        '.amenities-grid',
        '.services-grid',
        '.team-grid',
        '.events-grid',
        '.gallery-grid',
    ].join(', ');

    /* =========================================================================
       Annotation — add reveal classes to DOM nodes
    ========================================================================= */

    function annotate(root) {
        const desktop = isDesktop();
        const reduced = reducedMotion();

        // ── Section-level reveals ─────────────────────────────────────────
        root.querySelectorAll(SECTION_SEL).forEach(el => {
            // Skip if already annotated, or if inside hero
            if (el.classList.contains('rh-reveal')) return;
            if (el.closest('.hero, [class*="hero--"]'))  return;

            el.classList.add('rh-reveal');
            if (desktop && !reduced) {
                el.classList.add('rh-reveal--up');
            }
        });

        // ── Staggered grid children ───────────────────────────────────────
        if (desktop && !reduced) {
            root.querySelectorAll(STAGGER_CONTAINERS).forEach(container => {
                // If the container itself already IS a reveal target, skip it —
                // but still stagger its children
                Array.from(container.children).forEach((child, i) => {
                    if (child.classList.contains('rh-reveal')) return;
                    if (child.closest('.hero, [class*="hero--"]')) return;

                    // Cap stagger at 6 items to keep the effect snappy
                    const delayMs = Math.min(i, 5) * 90;

                    child.classList.add('rh-reveal', 'rh-reveal--up-sm', 'rh-stagger-item');
                    child.style.setProperty('--rh-stagger-delay', `${delayMs}ms`);
                });
            });

            // ── Experience / feature icon cards ──────────────────────────
            root.querySelectorAll(
                '.editorial-experience-item, .experience-item, .feature-card, .service-card'
            ).forEach((card, i) => {
                if (card.classList.contains('rh-reveal')) return;
                const delayMs = Math.min(i % 4, 3) * 100;
                card.classList.add('rh-reveal', 'rh-reveal--scale', 'rh-stagger-item');
                card.style.setProperty('--rh-stagger-delay', `${delayMs}ms`);
            });

            // ── Section header dividers / decorative lines ────────────────
            root.querySelectorAll(
                '.section-header__line, .divider-line, [class*="section-divider"]'
            ).forEach(line => {
                if (line.classList.contains('rh-reveal')) return;
                line.classList.add('rh-reveal', 'rh-reveal--line');
            });
        }
    }

    /* =========================================================================
       Observer
    ========================================================================= */

    let _observer = null;

    function getObserver() {
        if (_observer) return _observer;
        if (!('IntersectionObserver' in window)) return null;

        // Use tighter threshold on desktop for more precise trigger points;
        // looser on mobile so content reveals before the user scrolls past it.
        const isMobile = window.matchMedia('(max-width: 767px)').matches;
        const threshold = isMobile ? 0.05 : 0.08;
        const rootMargin = isMobile ? '0px 0px -32px 0px' : '0px 0px -72px 0px';

        _observer = new IntersectionObserver((entries, obs) => {
            entries.forEach(entry => {
                if (!entry.isIntersecting) return;
                entry.target.classList.add('is-revealed');
                obs.unobserve(entry.target);
            });
        }, { threshold, rootMargin });

        return _observer;
    }

    function observeAll(root) {
        const obs = getObserver();

        root.querySelectorAll('.rh-reveal:not(.is-revealed)').forEach(el => {
            // Skip elements inside the hero (hero has its own CSS animations)
            if (el.closest('.hero, [class*="hero--"]')) {
                el.classList.add('is-revealed'); // keep them visible
                return;
            }

            if (obs) {
                obs.observe(el);
            } else {
                // No IntersectionObserver support — reveal immediately
                el.classList.add('is-revealed');
            }
        });
    }

    /* =========================================================================
       Public API
    ========================================================================= */

    function init(root) {
        injectStyles();
        // Reset any pre-revealed elements so desktop gets proper lazy entrance
        resetPreRevealed(root);
        annotate(root);
        observeAll(root);
    }

    function reinit() {
        // Small delay lets the SPA swap finish painting before we measure
        setTimeout(() => init(document), 80);
    }

    /* =========================================================================
       Boot
    ========================================================================= */

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => init(document), { once: true });
    } else {
        init(document);
    }

    // SPA navigation hook
    window.addEventListener('spa:contentLoaded', reinit);

    // Expose for external callers (navigation-unified.js _reinit uses this)
    window.ScrollLazyAnimations = { init, reinit };

})();
