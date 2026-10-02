<?php

/**
 * Display size — one standard for the POS, the station boards and the admin portal.
 *
 * Staff found every screen too large at 100% on the tablets and preferred how it
 * looked at 60% browser zoom. Browser zoom is per site and easy to lose, so each
 * surface scales itself with CSS zoom instead:
 *   - default 60% on touch tablets, 100% on everything else;
 *   - one setting per device (localStorage `rh_page_zoom`), changed from the
 *     Display size control (− 60% +) on any surface, applies to all of them;
 *   - the root gets `.page-zoomed` and `--page-zoom`; each surface's stylesheet
 *     divides anything sized to the screen by `--page-zoom` so it still fills it;
 *   - JS that turns screen coordinates into CSS lengths divides by pageZoom().
 *
 * Usage: rh_page_zoom_bootstrap() as early as possible (before the page paints),
 * and rh_page_zoom_control() wherever the control belongs.
 */

if (!function_exists('rh_page_zoom_bootstrap')) {
    function rh_page_zoom_bootstrap(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        require_once __DIR__ . '/../../includes/admin-session.php';
        rh_admin_client_script(); // hotel time offset + session-expiry redirect
        ?>
<script>
(function () {
    var KEY = 'rh_page_zoom';
    var LEGACY_KEYS = ['rh_pos_zoom', 'rh_kds_zoom']; // per-screen settings before this was one
    var STEPS = [0.5, 0.6, 0.7, 0.8, 0.9, 1];

    function valid(v) { return v >= 0.5 && v <= 1; }
    function deviceDefault() {
        try {
            return (Math.min(screen.width, screen.height) >= 600 && window.matchMedia('(pointer: coarse)').matches) ? 0.6 : 1;
        } catch (e) { return 1; }
    }
    function readSaved() {
        try {
            var v = parseFloat(localStorage.getItem(KEY) || '');
            if (valid(v)) return v;
            for (var i = 0; i < LEGACY_KEYS.length; i++) {
                v = parseFloat(localStorage.getItem(LEGACY_KEYS[i]) || '');
                if (valid(v)) return v;
            }
        } catch (e) {}
        return null;
    }
    function apply(z) {
        window.__rhPageZoom = z;
        var root = document.documentElement;
        if (z < 1) {
            root.style.setProperty('--page-zoom', String(z));
            root.classList.add('page-zoomed');
        } else {
            root.style.removeProperty('--page-zoom');
            root.classList.remove('page-zoomed');
        }
        var label = Math.round(z * 100) + '%';
        var outs = document.querySelectorAll('[data-page-zoom-value]');
        for (var i = 0; i < outs.length; i++) outs[i].textContent = label;
    }
    function set(z) {
        if (!valid(z)) return;
        try { localStorage.setItem(KEY, String(z)); } catch (e) {}
        apply(z);
        // Anything sized from the window (menus, docked panels, bar heights)
        // listens for resize; surfaces with more to redo listen for this event.
        window.dispatchEvent(new Event('resize'));
        window.dispatchEvent(new CustomEvent('rh:pagezoom', { detail: { zoom: z } }));
    }
    function step(dir) {
        var cur = window.__rhPageZoom || 1;
        var i = -1;
        for (var k = 0; k < STEPS.length; k++) if (Math.abs(STEPS[k] - cur) < 0.001) i = k;
        if (i < 0) i = STEPS.length - 1;
        set(STEPS[Math.max(0, Math.min(STEPS.length - 1, i + (dir < 0 ? -1 : 1)))]);
    }

    window.RHPageZoom = { steps: STEPS, get: function () { return window.__rhPageZoom || 1; }, set: set, step: step };
    /* Screen coordinates (getBoundingClientRect, pointer events) are in real
       pixels; CSS lengths inside the scaled page are not. Divide by this. */
    window.pageZoom = window.RHPageZoom.get;
    window.posZoom = window.RHPageZoom.get; // name the till's code already uses

    var saved = readSaved();
    apply(saved !== null ? saved : deviceDefault());

    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('[data-page-zoom-step]') : null;
        if (!btn) return;
        e.preventDefault();
        e.stopPropagation();
        step(parseInt(btn.getAttribute('data-page-zoom-step'), 10) || 1);
    }, true);
    document.addEventListener('DOMContentLoaded', function () { apply(window.__rhPageZoom || 1); });
})();
</script>
        <?php
    }

    /**
     * The Display size control: a label and a − value + stepper. $wrapClass and
     * $ctlClass let each surface style it to match its own menu.
     */
    function rh_page_zoom_control(string $wrapClass = 'rh-zoom', string $ctlClass = 'rh-zoom__ctl'): void
    {
        ?>
<div class="<?php echo htmlspecialchars($wrapClass); ?>">
    <span><i class="fas fa-magnifying-glass" aria-hidden="true"></i> Display size</span>
    <span class="<?php echo htmlspecialchars($ctlClass); ?>">
        <button type="button" data-page-zoom-step="-1" aria-label="Make the screen smaller">&minus;</button>
        <output data-page-zoom-value>100%</output>
        <button type="button" data-page-zoom-step="1" aria-label="Make the screen larger">+</button>
    </span>
</div>
        <?php
    }
}
