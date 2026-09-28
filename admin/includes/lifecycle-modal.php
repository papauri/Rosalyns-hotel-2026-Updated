<?php

/**
 * Shared "order log" overlay.
 *
 * Every admin surface that links to order-lifecycle.php used to do it with
 * target="_blank". On the station screens that is actively harmful — they run
 * fullscreen on a wall or a counter tablet, so a new tab takes the board off
 * the pass with no browser chrome to get back with — and on the desktop pages
 * it still scatters the operator across tabs mid-service.
 *
 * Including this file once gives the page rhOpenLifecycle(orderId): the same
 * log, rendered with ?embed=1 inside an overlay on the current page. The POS
 * keeps its own modal (openPosPageModal) and the KDS its own drawer; this is
 * for the pages that had neither.
 *
 * Usage: require once near the end of <body>, then call
 *        rhOpenLifecycle(<order id>) from a button.
 */

if (defined('RH_LIFECYCLE_MODAL_RENDERED')) {
    return;
}
define('RH_LIFECYCLE_MODAL_RENDERED', true);
?>
<div id="rhLifecycleOverlay" class="rh-lc" hidden>
    <div class="rh-lc__bg" onclick="rhCloseLifecycle()"></div>
    <div class="rh-lc__card" role="dialog" aria-modal="true" aria-labelledby="rhLifecycleTitle">
        <div class="rh-lc__head">
            <h3 id="rhLifecycleTitle"><i class="fas fa-stream"></i> Order log</h3>
            <button type="button" class="rh-lc__close" onclick="rhCloseLifecycle()" aria-label="Close"><i class="fas fa-times"></i></button>
        </div>
        <div class="rh-lc__body"><iframe id="rhLifecycleFrame" title="Order lifecycle"></iframe></div>
    </div>
</div>
<style>
    .rh-lc {
        position: fixed;
        inset: 0;
        z-index: 100300;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 24px;
    }

    .rh-lc[hidden] {
        display: none;
    }

    .rh-lc__bg {
        position: absolute;
        inset: 0;
        background: rgba(42, 39, 35, .52);
    }

    .rh-lc__card {
        position: relative;
        display: flex;
        flex-direction: column;
        width: 100%;
        max-width: 1000px;
        max-height: calc(100dvh - 48px);
        background: #fffdfb;
        border: 1px solid #dccfc2;
        border-radius: 10px;
        box-shadow: 0 18px 48px rgba(42, 39, 35, .28);
        overflow: hidden;
    }

    .rh-lc__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 12px 16px;
        border-bottom: 1px solid #eae1d8;
        background: #f4efe9;
    }

    .rh-lc__head h3 {
        margin: 0;
        font-size: 15px;
        font-weight: 700;
        color: #2a2723;
        display: flex;
        align-items: center;
        gap: 9px;
    }

    .rh-lc__head i {
        color: #8a775f;
    }

    .rh-lc__close {
        width: 36px;
        height: 36px;
        border: 1px solid #dccfc2;
        border-radius: 8px;
        background: #fffdfb;
        color: #5e554d;
        cursor: pointer;
    }

    .rh-lc__close:hover {
        background: #f2e8e3;
        color: #956a5b;
    }

    .rh-lc__body {
        flex: 1;
        min-height: 0;
    }

    .rh-lc__body iframe {
        width: 100%;
        height: min(74vh, 800px);
        border: 0;
        display: block;
        background: #fffdfb;
    }
</style>
<script>
    function rhOpenLifecycle(orderId) {
        var id = parseInt(orderId, 10) || 0;
        if (!id) return;
        var o = document.getElementById('rhLifecycleOverlay');
        var f = document.getElementById('rhLifecycleFrame');
        if (!o || !f) return;
        f.src = 'order-lifecycle.php?embed=1&id=' + encodeURIComponent(id);
        o.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function rhCloseLifecycle() {
        var o = document.getElementById('rhLifecycleOverlay');
        var f = document.getElementById('rhLifecycleFrame');
        if (!o) return;
        o.hidden = true;
        /* Drop the frame so a closed log stops loading and the next open starts
           clean rather than flashing the previous order. */
        if (f) f.removeAttribute('src');
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') rhCloseLifecycle();
    });
</script>
