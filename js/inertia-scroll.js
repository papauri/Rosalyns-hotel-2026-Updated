/**
 * Inertia Scroll — eased momentum scrolling for the guest frontend.
 *
 * page-transitions.js already expects a `window.inertiaScroll` with
 * .scrollTo(y), .on(cb) and .onResize(), but no module ever defined it, so
 * every one of those call sites silently fell through to the unsmoothed
 * fallback. This provides that API.
 *
 * Deliberately conservative about when it takes over the wheel:
 *   - never on coarse pointers (touch momentum is already better than
 *     anything we can emulate, and hijacking it feels broken)
 *   - never under prefers-reduced-motion
 *   - never over a nested scrollable (modals, selects, long code blocks)
 *   - never while body scroll is locked (open modal / mobile menu)
 *
 * Keyboard, scrollbar dragging and browser find-in-page keep working
 * natively; the module resyncs from window.scrollY whenever it is idle, so
 * those never fight the animation.
 */
(function () {
    'use strict';

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var coarse = window.matchMedia('(pointer: coarse)').matches;

    var subscribers = [];
    var maxScroll = 0;
    var target = window.pageYOffset || 0;
    var current = target;
    var running = false;
    var hijacking = false;

    // How quickly current catches up to target each frame. Higher is snappier;
    // 0.14 glides without feeling laggy or seasick.
    var EASE = 0.14;
    var SETTLE = 0.4; // px — close enough to stop animating

    function recalcMax() {
        maxScroll = Math.max(
            0,
            document.documentElement.scrollHeight - window.innerHeight
        );
    }

    function emit(y) {
        for (var i = 0; i < subscribers.length; i++) {
            try {
                subscribers[i]({ y: y });
            } catch (e) {
                /* a bad subscriber must not stop the scroll loop */
            }
        }
    }

    function frame() {
        var delta = target - current;

        if (Math.abs(delta) < SETTLE) {
            current = target;
            window.scrollTo(0, Math.round(current));
            emit(current);
            running = false;
            hijacking = false;
            return;
        }

        current += delta * EASE;
        window.scrollTo(0, Math.round(current));
        emit(current);
        requestAnimationFrame(frame);
    }

    function start() {
        if (running) return;
        running = true;
        requestAnimationFrame(frame);
    }

    /** True when the wheel should be left alone for this event. */
    function shouldIgnore(e) {
        if (e.ctrlKey || e.metaKey) return true; // pinch/zoom

        // Body scroll locked by a modal or the mobile menu.
        var bodyOverflow = window.getComputedStyle(document.body).overflow;
        if (bodyOverflow === 'hidden') return true;

        // Any ancestor that can itself scroll in this direction.
        var node = e.target;
        while (node && node !== document.body && node !== document.documentElement) {
            if (node.nodeType === 1) {
                if (node.hasAttribute('data-native-scroll')) return true;
                var cs = window.getComputedStyle(node);
                var oy = cs.overflowY;
                if ((oy === 'auto' || oy === 'scroll') &&
                    node.scrollHeight > node.clientHeight + 1) {
                    var atTop = node.scrollTop <= 0;
                    var atBottom =
                        node.scrollTop + node.clientHeight >= node.scrollHeight - 1;
                    // Only defer while it still has room to move that way.
                    if ((e.deltaY < 0 && !atTop) || (e.deltaY > 0 && !atBottom)) {
                        return true;
                    }
                }
            }
            node = node.parentNode;
        }
        return false;
    }

    function normalize(e) {
        if (e.deltaMode === 1) return e.deltaY * 16;      // lines
        if (e.deltaMode === 2) return e.deltaY * window.innerHeight; // pages
        return e.deltaY;                                   // pixels
    }

    function onWheel(e) {
        if (shouldIgnore(e)) return;

        recalcMax();
        // Resync before taking over, so scrollbar drags and keyboard scrolls
        // that happened since the last animation are respected.
        if (!hijacking) current = window.pageYOffset;

        e.preventDefault();
        hijacking = true;
        target = Math.min(maxScroll, Math.max(0, target + normalize(e)));

        // If a native scroll moved us far from target, rebase.
        if (Math.abs(target - window.pageYOffset) > window.innerHeight * 2) {
            target = Math.min(
                maxScroll,
                Math.max(0, window.pageYOffset + normalize(e))
            );
            current = window.pageYOffset;
        }
        start();
    }

    // Keep target honest when the page is scrolled by anything else.
    function onNativeScroll() {
        if (!running) {
            current = window.pageYOffset;
            target = current;
            emit(current);
        }
    }

    var api = {
        /** Animate to an absolute Y position. */
        scrollTo: function (y) {
            recalcMax();
            if (typeof y !== 'number' || isNaN(y)) return;
            target = Math.min(maxScroll, Math.max(0, y));
            if (reduced || coarse) {
                window.scrollTo(0, Math.round(target));
                current = target;
                emit(current);
                return;
            }
            current = window.pageYOffset;
            hijacking = true;
            start();
        },
        /** Subscribe to scroll position updates: cb({ y }). */
        on: function (cb) {
            if (typeof cb === 'function') subscribers.push(cb);
        },
        off: function (cb) {
            var i = subscribers.indexOf(cb);
            if (i > -1) subscribers.splice(i, 1);
        },
        onResize: recalcMax,
        get y() {
            return window.pageYOffset;
        },
        enabled: !(reduced || coarse)
    };

    window.inertiaScroll = api;

    recalcMax();
    window.addEventListener('resize', recalcMax, { passive: true });
    window.addEventListener('load', recalcMax);
    window.addEventListener('scroll', onNativeScroll, { passive: true });

    /* Wheel takeover is OFF by default, and that is a deliberate call.
     *
     * Measured on this site at 1440px, scrolling the home page:
     *     native wheel   16.7ms avg, p95 16.7ms,  0 frames over 33ms
     *     hijacked wheel 23.7ms avg, p95 33.4ms, 76 frames over 33ms
     *
     * Native scrolling is handled on the compositor thread; driving it from
     * JS moves it onto the main thread, where it competes with the fixed
     * header's backdrop-filter blur and the scroll-reveal observers. The
     * glide looks nice in isolation but costs real smoothness, so it is not
     * the default.
     *
     * Opt in per-page or globally with either:
     *     <html data-inertia-wheel>            (markup)
     *     window.INERTIA_WHEEL = true;         (before this script runs)
     * Reduced-motion and touch devices always keep native scrolling.
     */
    var wheelOptIn =
        document.documentElement.hasAttribute('data-inertia-wheel') ||
        window.INERTIA_WHEEL === true;

    if (wheelOptIn && !reduced && !coarse) {
        window.addEventListener('wheel', onWheel, { passive: false });
    } else {
        api.enabled = false;
    }
})();
