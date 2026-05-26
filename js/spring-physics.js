/*
 * Spring physics helper (minimal utility)
 */
(function () {
    'use strict';

    if (window.__rhSpringPhysicsLoaded) return;
    window.__rhSpringPhysicsLoaded = true;

    function springLerp(current, target, stiffness, damping) {
        const safeStiffness = typeof stiffness === 'number' ? stiffness : 0.12;
        const safeDamping = typeof damping === 'number' ? damping : 0.8;
        const velocity = (target - current) * safeStiffness;
        return current + velocity * safeDamping;
    }

    window.RHSpring = window.RHSpring || {
        lerp: springLerp
    };
})();

