/*
 * Decorative cursor follower (disabled on touch/reduced-motion)
 */
(function () {
    'use strict';

    if (window.__rhCursorFollowerLoaded) return;
    window.__rhCursorFollowerLoaded = true;

    const reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const coarsePointer = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    if (reduced || coarsePointer) return;

    const follower = document.createElement('div');
    follower.className = 'cursor-follower';
    follower.setAttribute('aria-hidden', 'true');
    follower.style.cssText = [
        'position:fixed',
        'left:0',
        'top:0',
        'width:14px',
        'height:14px',
        'border-radius:50%',
        'pointer-events:none',
        'z-index:9999',
        'opacity:.55',
        'background:rgba(212,168,67,.55)',
        'transform:translate3d(-100px,-100px,0)',
        'transition:transform .08s linear'
    ].join(';');

    document.body.appendChild(follower);

    let raf = 0;
    let x = -100;
    let y = -100;

    function paint() {
        follower.style.transform = `translate3d(${x - 7}px, ${y - 7}px, 0)`;
        raf = 0;
    }

    window.addEventListener('mousemove', (e) => {
        x = e.clientX;
        y = e.clientY;
        if (!raf) raf = requestAnimationFrame(paint);
    }, { passive: true });
})();

