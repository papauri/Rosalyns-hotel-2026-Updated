<?php

/**
 * Help-tooltip system shared by POS + KDS.
 *
 * Usage: include this file at the very end of <body> on any page that wants
 * the toggleable hover / press-and-hold tooltips. Then add `data-help="..."`
 * to any button or element that should show a help bubble.
 *
 * Behaviour:
 *   - Desktop: hovering a [data-help] element for 600ms shows a bubble.
 *   - Touch:   long-press (600ms) shows a bubble until released or tapped.
 *   - The bubble is suppressed when "Help" toggle is off (saved per-browser
 *     in localStorage as `rh_help_enabled`).
 *   - Toggle pill can be rendered as floating (default) or inline in page nav.
 *   - Pressing `?` toggles help mode (keyboard shortcut).
 *
 * Designed to be <12kb, framework-free, dependency-free.
 */

// Prevent duplicate markup/scripts when this include is loaded both page-level
// and from admin-footer in the same request.
if (defined('RH_HELP_TOOLTIPS_RENDERED')) {
    return;
}
define('RH_HELP_TOOLTIPS_RENDERED', true);
?>
<link rel="stylesheet" href="css/help-tooltips.css">
<?php $rhHelpRenderFloating = !isset($rh_help_hide_fab) || !$rh_help_hide_fab; ?>
<?php $rhHelpAllowFallback = !isset($rh_help_disable_fallback) || !$rh_help_disable_fallback; ?>
<?php if ($rhHelpRenderFloating): ?>
    <button type="button" class="rh-help-toggle" id="rhHelpFloatingToggle" aria-label="Toggle help tooltips and drag to reposition" data-help="Helper button|Click to turn hints on or off. Drag this button up or down to move it on the right side.">
        <span class="dot"></span><i class="fas fa-question-circle"></i><i class="fas fa-grip-lines-vertical rh-help-drag-icon" aria-hidden="true"></i> <span data-rh-help-label>Help</span>
    </button>
<?php endif; ?>
<div class="rh-help-bubble" id="rhHelpBubble" role="tooltip" aria-hidden="true"></div>
<script>
    (function() {
        'use strict';
        const KEY = 'rh_help_enabled';
        const POS_KEY = 'rh_help_toggle_top_v1';
        const ALLOW_FALLBACK = <?php echo $rhHelpAllowFallback ? 'true' : 'false'; ?>;
        const HELP_TARGET_SELECTOR = '[data-help], .help[data-tip]';
        const bubble = document.getElementById('rhHelpBubble');
        if (!bubble) return;

        function getHelpTarget(node) {
            if (!(node instanceof Element)) return null;
            return node.closest(HELP_TARGET_SELECTOR);
        }

        function isElementVisible(el) {
            if (!el) return false;
            const style = window.getComputedStyle(el);
            if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') return false;
            return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
        }

        function allToggles() {
            return Array.from(document.querySelectorAll('.rh-help-toggle'));
        }

        function createFloatingFallbackToggle() {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'rh-help-toggle';
            btn.id = 'rhHelpToggleFallback';
            btn.setAttribute('aria-label', 'Toggle help tooltips and drag to reposition');
            btn.setAttribute('data-help', 'Helper button|Click to turn hints on or off. Drag this button up or down to move it on the right side.');
            btn.innerHTML = '<span class="dot"></span><i class="fas fa-question-circle"></i><i class="fas fa-grip-lines-vertical rh-help-drag-icon" aria-hidden="true"></i> <span data-rh-help-label>Help</span>';
            document.body.appendChild(btn);
            return btn;
        }

        function pickPrimaryToggle() {
            const toggles = allToggles();
            const visibleFloating = toggles.find(function(el) {
                return el.getAttribute('data-inline') !== '1' && isElementVisible(el);
            });
            if (visibleFloating) return visibleFloating;

            const visibleAny = toggles.find(isElementVisible);
            if (visibleAny) return visibleAny;

            return null;
        }

        let toggle = pickPrimaryToggle();
        if (!toggle && ALLOW_FALLBACK) {
            toggle = createFloatingFallbackToggle();
        }

        /* Help badges default ON for back-office pages (occasional users, lots of unfamiliar
         * screens) but OFF for the till and the station displays. Those are used all shift by
         * staff who already know them, and turning every action into a button wearing a yellow
         * "?" put 30-odd markers on one screen — they overlapped the labels underneath
         * ("Settle" reading as "Settl") and made a busy service screen busier. Anyone who wants
         * them can still hit the Help toggle, and the choice persists per device. */
        const isFrontOfHouseScreen = document.body.classList.contains('pos-screen')
            || document.body.classList.contains('station-screen');
        const storedHelpPref = localStorage.getItem(KEY);
        let enabled = storedHelpPref !== null
            ? storedHelpPref === '1'
            : !isFrontOfHouseScreen;
        /* "No visible toggle, so force help on" exists so back-office pages can't end up with
         * badges permanently unavailable. It must NOT apply to the till: below 1280px the
         * toolbar (and the inline toggle inside it) is display:none, pickPrimaryToggle() only
         * returns VISIBLE toggles, so every tablet and phone till hit this branch and forced
         * badges back on — on exactly the screens with least room for them. Those screens do
         * have a reachable control: "Help Tooltips" in the hamburger menu. */
        if (!toggle && !isFrontOfHouseScreen) enabled = true;
        let hoverTimer = null;
        let pressTimer = null;
        let activeEl = null;
        let touchTapArmedEl = null;
        let touchTapArmedUntil = 0;

        // Drag state (floating toggle only)
        let dragArmed = false;
        let dragStarted = false;
        let dragOffsetY = 0;
        let dragStartY = 0;
        let suppressNextClick = false;

        function clamp(v, min, max) {
            return Math.min(max, Math.max(min, v));
        }

        function isInlineToggle() {
            return !!(toggle && toggle.getAttribute('data-inline') === '1');
        }

        function setToggleTop(topPx) {
            if (!toggle || isInlineToggle()) return;
            const maxTop = Math.max(8, window.innerHeight - toggle.offsetHeight - 8);
            const safeTop = clamp(Math.round(topPx), 8, maxTop);
            toggle.style.top = safeTop + 'px';
            toggle.style.bottom = 'auto';
            toggle.style.right = '14px';
        }

        function saveToggleTop(topPx) {
            if (!toggle || isInlineToggle()) return;
            localStorage.setItem(POS_KEY, String(Math.round(topPx)));
        }

        function loadToggleTop() {
            if (!toggle || isInlineToggle()) return;
            const raw = localStorage.getItem(POS_KEY);
            if (raw === null || raw === '') return;
            const parsed = Number(raw);
            if (Number.isNaN(parsed)) return;
            setToggleTop(parsed);
        }

        function clampToggleTopOnResize() {
            if (!toggle || isInlineToggle()) return;
            if (!toggle.style.top) return;
            const currentTop = Number((toggle.style.top || '').replace('px', ''));
            if (Number.isNaN(currentTop)) return;
            setToggleTop(currentTop);
        }

        function onTogglePointerMove(e) {
            if (!dragArmed || !toggle) return;
            const delta = Math.abs(e.clientY - dragStartY);
            if (!dragStarted && delta < 4) return;
            dragStarted = true;
            setToggleTop(e.clientY - dragOffsetY);
            toggle.classList.add('dragging');
        }

        function onTogglePointerUp() {
            if (!dragArmed || !toggle) return;
            dragArmed = false;
            window.removeEventListener('pointermove', onTogglePointerMove);
            toggle.classList.remove('dragging');

            if (dragStarted) {
                suppressNextClick = true;
                const topNow = Number((toggle.style.top || '').replace('px', ''));
                if (!Number.isNaN(topNow)) saveToggleTop(topNow);
            }
        }

        function initToggleDrag() {
            if (!toggle || isInlineToggle()) return;
            toggle.classList.add('is-draggable');
            loadToggleTop();

            toggle.addEventListener('pointerdown', function(e) {
                if (typeof e.button === 'number' && e.button !== 0) return;
                const rect = toggle.getBoundingClientRect();
                dragArmed = true;
                dragStarted = false;
                dragStartY = e.clientY;
                dragOffsetY = e.clientY - rect.top;

                window.addEventListener('pointermove', onTogglePointerMove);
                window.addEventListener('pointerup', onTogglePointerUp, {
                    once: true
                });
                window.addEventListener('pointercancel', onTogglePointerUp, {
                    once: true
                });
            });
        }

        function applyState() {
            document.body.classList.toggle('rh-help-on', enabled);
            allToggles().forEach(function(btn) {
                btn.classList.toggle('on', enabled);
                const btnLabel = btn.querySelector('[data-rh-help-label], #rhHelpLabel');
                if (btnLabel) {
                    btnLabel.textContent = enabled ? 'Help: ON' : 'Help';
                }
            });
            if (!enabled) hideBubble();
        }

        function setEnabled(v) {
            enabled = !!v;
            localStorage.setItem(KEY, enabled ? '1' : '0');
            applyState();
        }

        function showBubble(target, x, y, forceShow) {
            if (!enabled && !forceShow) return;
            const raw = target.getAttribute('data-help') || target.getAttribute('data-tip') || '';
            if (!raw) return;
            // Format: "Title|Body" or just "Body"; supports `|` separator and \n for line breaks.
            let title = '',
                body = raw;
            const sep = raw.indexOf('|');
            if (sep > 0) {
                title = raw.slice(0, sep).trim();
                body = raw.slice(sep + 1).trim();
            }
            const safe = (s) => String(s).replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            } [c])).replace(/\n/g, '<br>');
            bubble.innerHTML = (title ? '<strong>' + safe(title) + '</strong>' : '') + safe(body);
            bubble.style.left = '0px';
            bubble.style.top = '0px';
            bubble.classList.add('show');
            bubble.setAttribute('aria-hidden', 'false');
            // Position after measure
            const r = target.getBoundingClientRect();
            const bw = bubble.offsetWidth,
                bh = bubble.offsetHeight;
            let bx = (typeof x === 'number') ? x + 12 : (r.left + r.width / 2 - bw / 2);
            let by = (typeof y === 'number') ? y + 16 : (r.top - bh - 10);
            if (by < 8) by = r.bottom + 10;
            if (bx + bw > window.innerWidth - 8) bx = window.innerWidth - bw - 8;
            if (bx < 8) bx = 8;
            if (by + bh > window.innerHeight - 8) by = window.innerHeight - bh - 8;
            bubble.style.left = bx + 'px';
            bubble.style.top = by + 'px';
            activeEl = target;
        }

        function hideBubble() {
            bubble.classList.remove('show');
            bubble.setAttribute('aria-hidden', 'true');
            activeEl = null;
        }

        function bindToggleClicks() {
            allToggles().forEach(function(btn) {
                if (btn.dataset.rhHelpBound === '1') return;
                btn.dataset.rhHelpBound = '1';
                btn.addEventListener('click', function(e) {
                    if (suppressNextClick && btn === toggle) {
                        suppressNextClick = false;
                        e.preventDefault();
                        e.stopPropagation();
                        return;
                    }
                    setEnabled(!enabled);
                });
            });
        }

        // ----- Mouse hover (desktop) -----
        document.addEventListener('mouseover', e => {
            const t = getHelpTarget(e.target);
            if (!t) return;
            const from = getHelpTarget(e.relatedTarget);
            if (from === t) return;
            const alwaysOn = t.classList.contains('help') && t.hasAttribute('data-tip');
            if (!alwaysOn && !enabled) return;
            clearTimeout(hoverTimer);
            hoverTimer = setTimeout(() => showBubble(t, undefined, undefined, alwaysOn), 500);
        });
        document.addEventListener('mouseout', e => {
            const from = getHelpTarget(e.target);
            if (!from) return;
            const to = getHelpTarget(e.relatedTarget);
            if (from === to) return;
            clearTimeout(hoverTimer);
            if (activeEl === from) hideBubble();
        });
        document.addEventListener('mousemove', e => {
            if (activeEl && !getHelpTarget(e.target)) hideBubble();
        });

        // ----- Long-press (touch) -----
        document.addEventListener('touchstart', e => {
            const t = e.target.closest(HELP_TARGET_SELECTOR);
            if (!t) return;
            const touch = e.touches[0];
            const x = touch.clientX,
                y = touch.clientY;
            clearTimeout(pressTimer);
            pressTimer = setTimeout(() => {
                // Only fire when help is enabled; long-press while OFF acts as a one-shot peek
                const wasEnabled = enabled;
                if (!wasEnabled) document.body.classList.add('rh-help-on');
                const oldEnabled = enabled;
                enabled = true;
                showBubble(t, x, y);
                enabled = oldEnabled;
                if (!wasEnabled) {
                    // Auto-hide after 3.5s if user hasn't enabled help mode permanently
                    setTimeout(() => {
                        if (!enabled) {
                            hideBubble();
                            document.body.classList.remove('rh-help-on');
                        }
                    }, 3500);
                }
            }, 600);
        }, {
            passive: true
        });
        document.addEventListener('touchend', () => {
            clearTimeout(pressTimer);
        });
        document.addEventListener('touchmove', () => {
            clearTimeout(pressTimer);
        });

        // On touch-first devices in help mode: first tap shows help, second tap runs the action.
        document.addEventListener('click', e => {
            const t = getHelpTarget(e.target);
            if (!t || !enabled) return;
            if (t.classList.contains('rh-help-toggle')) return;

            const touchFirstDevice = window.matchMedia('(hover: none)').matches || window.matchMedia('(pointer: coarse)').matches;
            if (!touchFirstDevice) return;

            // Do not require double-tap for real action controls.
            // Long-press still shows contextual help for these elements.
            if (t.closest('button, a[href], input, select, textarea, summary, [role="button"], [data-action], .quick-action, .actions-more-toggle')) {
                return;
            }

            const now = Date.now();
            if (touchTapArmedEl === t && now < touchTapArmedUntil) {
                touchTapArmedEl = null;
                touchTapArmedUntil = 0;
                return;
            }

            const r = t.getBoundingClientRect();
            showBubble(t, r.left + (r.width / 2), r.top + Math.min(r.height, 24), true);
            touchTapArmedEl = t;
            touchTapArmedUntil = now + 2500;

            e.preventDefault();
            e.stopPropagation();
        }, true);

        // ----- Toggle button + keyboard -----
        bindToggleClicks();
        document.addEventListener('keydown', e => {
            if (e.key === '?' || (e.key === '/' && e.shiftKey)) {
                e.preventDefault();
                setEnabled(!enabled);
            }
            if (e.key === 'Escape') hideBubble();
        });

        // Hide bubble on scroll / resize / click anywhere
        window.addEventListener('scroll', hideBubble, true);
        window.addEventListener('resize', () => {
            hideBubble();
            clampToggleTopOnResize();
        });
        document.addEventListener('click', e => {
            if (!getHelpTarget(e.target) && !e.target.closest('.rh-help-toggle')) hideBubble();
        }, true);

        initToggleDrag();
        applyState();
    })();
</script>

