/**
 * admin-walkme.js — Shared WalkMe tour & tooltip engine
 *
 * Usage (per-page):
 *   AdminWalkMe.tooltip('[data-tooltip]');  // auto-wire all elements with data-tooltip attr
 *   AdminWalkMe.startTour('payment-add');   // launch a named tour
 *   AdminWalkMe.registerTour('my-tour', steps); // register custom tour steps
 *
 * Tour step shape:
 *   {
 *     target:  CSS selector | null (centered card),
 *     icon:    FA icon class e.g. 'fa-credit-card',
 *     label:   eyebrow text,
 *     title:   card heading,
 *     text:    body copy (HTML allowed),
 *     placement: 'bottom' | 'top' | 'left' | 'right' (default: auto),
 *   }
 */
(function (global) {
    'use strict';

    /* ─── Storage helpers ─────────────────────────────── */
    const STORAGE_PREFIX = 'rh_walkme_';

    function storageGet(key) {
        try { return localStorage.getItem(STORAGE_PREFIX + key); } catch (_) { return null; }
    }

    function storageSet(key, value) {
        try { localStorage.setItem(STORAGE_PREFIX + key, value); } catch (_) { /* no-op */ }
    }

    /* ─── Tour registry ───────────────────────────────── */
    const _tours = {};

    function registerTour(name, steps) {
        _tours[name] = steps;
    }

    /* ─── Tooltip engine ──────────────────────────────── */
    let _tooltipEl = null;

    function _getTooltip() {
        if (!_tooltipEl) {
            _tooltipEl = document.createElement('div');
            _tooltipEl.className = 'wm-tooltip';
            _tooltipEl.setAttribute('role', 'tooltip');
            _tooltipEl.innerHTML = '<span class="wm-tooltip__text"></span><span class="wm-tooltip__arrow"></span>';
            document.body.appendChild(_tooltipEl);
        }
        return _tooltipEl;
    }

    function _positionTooltip(anchor, placement) {
        const tip = _getTooltip();
        const rect = anchor.getBoundingClientRect();
        const tipRect = tip.getBoundingClientRect();
        const gap = 8;

        let top, left;
        const resolvedPlacement = placement || (rect.top > tipRect.height + gap + 20 ? 'top' : 'bottom');

        if (resolvedPlacement === 'top') {
            top = rect.top - tipRect.height - gap;
            left = rect.left + rect.width / 2 - tipRect.width / 2;
        } else {
            top = rect.bottom + gap;
            left = rect.left + rect.width / 2 - tipRect.width / 2;
        }

        // Clamp to viewport
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        left = Math.max(8, Math.min(left, vw - tipRect.width - 8));
        top  = Math.max(8, Math.min(top, vh - tipRect.height - 8));

        tip.style.left = left + 'px';
        tip.style.top  = top + 'px';
        tip.dataset.placement = resolvedPlacement;
    }

    function _showTooltip(anchor, text, placement) {
        const tip = _getTooltip();
        tip.querySelector('.wm-tooltip__text').textContent = text;
        tip.classList.add('is-visible');
        // Position after paint so tipRect is correct
        requestAnimationFrame(function () { _positionTooltip(anchor, placement); });
    }

    function _hideTooltip() {
        if (_tooltipEl) _tooltipEl.classList.remove('is-visible');
    }

    /**
     * Wire tooltip behaviour on all elements matching `selector` (or a single element).
     * Reads `data-tooltip` for text, `data-tooltip-placement` for placement.
     */
    function wireTooltips(root) {
        const container = root || document;
        const targets = container.querySelectorAll('[data-tooltip]');
        targets.forEach(function (el) {
            if (el.dataset.wmTooltipWired) return;
            el.dataset.wmTooltipWired = '1';

            const text      = el.dataset.tooltip;
            const placement = el.dataset.tooltipPlacement || null;

            el.addEventListener('mouseenter', function () { _showTooltip(el, text, placement); });
            el.addEventListener('focus',      function () { _showTooltip(el, text, placement); });
            el.addEventListener('mouseleave', _hideTooltip);
            el.addEventListener('blur',       _hideTooltip);
        });
    }

    /* ─── Help icon factory ───────────────────────────── */

    /**
     * Append a ? icon to a label element that shows a tooltip.
     *   AdminWalkMe.helpIcon(labelEl, 'Explanation text');
     */
    function helpIcon(labelEl, tooltipText) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'wm-help';
        btn.setAttribute('aria-label', 'Help');
        btn.setAttribute('tabindex', '0');
        btn.textContent = '?';
        btn.dataset.tooltip = tooltipText;
        labelEl.appendChild(btn);
        wireTooltips(labelEl);
        return btn;
    }

    /* ─── Tour engine ─────────────────────────────────── */

    let _tourActive   = false;
    let _tourName     = null;
    let _tourSteps    = [];
    let _tourIndex    = 0;
    let _tourOverlay  = null;
    let _tourCard     = null;
    let _tourHighlight = null;

    function _clearHighlight() {
        if (_tourHighlight) {
            _tourHighlight.classList.remove('wm-highlight');
            _tourHighlight = null;
        }
    }

    function _removeOverlay() {
        if (_tourOverlay) {
            _tourOverlay.remove();
            _tourOverlay = null;
        }
        if (_tourCard) {
            _tourCard.remove();
            _tourCard = null;
        }
        _clearHighlight();
    }

    function _buildOverlay() {
        _tourOverlay = document.createElement('div');
        _tourOverlay.className = 'wm-overlay';
        _tourOverlay.setAttribute('aria-hidden', 'true');

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.className = 'wm-overlay__svg';
        svg.setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        _tourOverlay.appendChild(svg);

        document.body.appendChild(_tourOverlay);
        return _tourOverlay;
    }

    function _updateOverlayCutout(rect) {
        if (!_tourOverlay) return;
        const svg = _tourOverlay.querySelector('svg');
        if (!svg) return;

        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const pad = 6;
        const r = 8;

        if (!rect) {
            // Full dim, no cutout
            svg.innerHTML = `<rect width="${vw}" height="${vh}" fill="rgba(16,10,5,0.55)"/>`;
            return;
        }

        const x = Math.max(0, rect.left - pad);
        const y = Math.max(0, rect.top  - pad);
        const w = Math.min(rect.width  + pad * 2, vw);
        const h = Math.min(rect.height + pad * 2, vh);

        svg.innerHTML = `
          <defs>
            <mask id="wm-cutout">
              <rect width="${vw}" height="${vh}" fill="white"/>
              <rect x="${x}" y="${y}" width="${w}" height="${h}" rx="${r}" fill="black"/>
            </mask>
          </defs>
          <rect width="${vw}" height="${vh}" fill="rgba(16,10,5,0.55)" mask="url(#wm-cutout)"/>
        `;
    }

    function _buildCard(step, index, total) {
        const card = document.createElement('div');
        card.className = 'wm-card';
        card.setAttribute('role', 'dialog');
        card.setAttribute('aria-modal', 'false');
        card.setAttribute('aria-label', step.title);

        // Progress dots
        let dots = '';
        for (let i = 0; i < total; i++) {
            const cls = i < index ? 'is-done' : (i === index ? 'is-active' : '');
            dots += `<span class="wm-card__dot ${cls}"></span>`;
        }

        card.innerHTML = `
          <div class="wm-card__accent"></div>
          <div class="wm-card__body">
            <div class="wm-card__eyebrow">
              <i class="fas ${step.icon || 'fa-lightbulb'}"></i>
              ${escHtml(step.label || 'Tip ' + (index + 1) + ' of ' + total)}
            </div>
            <h3 class="wm-card__title">${escHtml(step.title || '')}</h3>
            <p class="wm-card__text">${step.text || ''}</p>
          </div>
          <div class="wm-card__footer">
            <div class="wm-card__progress">${dots}</div>
            <div class="wm-card__nav">
              <button class="wm-btn wm-btn--danger" data-wm="end">End tour</button>
              ${index > 0 ? '<button class="wm-btn wm-btn--ghost" data-wm="prev"><i class="fas fa-arrow-left"></i> Back</button>' : ''}
              <button class="wm-btn wm-btn--primary" data-wm="next">
                ${index < total - 1 ? 'Next <i class="fas fa-arrow-right"></i>' : '<i class="fas fa-check"></i> Done'}
              </button>
            </div>
          </div>
        `;

        card.querySelector('[data-wm="end"]').addEventListener('click', endTour);
        const prevBtn = card.querySelector('[data-wm="prev"]');
        if (prevBtn) prevBtn.addEventListener('click', function () { _tourIndex--; _renderStep(); });
        card.querySelector('[data-wm="next"]').addEventListener('click', function () {
            if (_tourIndex < _tourSteps.length - 1) { _tourIndex++; _renderStep(); }
            else endTour();
        });

        return card;
    }

    function _positionCard(card, targetRect, placement) {
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        const gap = 16;

        // After DOM insertion
        const cardW = card.offsetWidth  || 352;
        const cardH = card.offsetHeight || 200;

        if (!targetRect) {
            // Centered
            card.style.left = Math.round((vw - cardW) / 2) + 'px';
            card.style.top  = Math.round((vh - cardH) / 2) + 'px';
            return;
        }

        const resolvedPlacement = placement || _autoPlacement(targetRect, cardH, cardW, vw, vh);

        let top, left;
        switch (resolvedPlacement) {
            case 'top':
                top  = targetRect.top - cardH - gap;
                left = targetRect.left + targetRect.width / 2 - cardW / 2;
                break;
            case 'left':
                top  = targetRect.top + targetRect.height / 2 - cardH / 2;
                left = targetRect.left - cardW - gap;
                break;
            case 'right':
                top  = targetRect.top + targetRect.height / 2 - cardH / 2;
                left = targetRect.right + gap;
                break;
            default: // bottom
                top  = targetRect.bottom + gap;
                left = targetRect.left + targetRect.width / 2 - cardW / 2;
        }

        // Clamp
        left = Math.max(gap, Math.min(left, vw - cardW - gap));
        top  = Math.max(gap, Math.min(top,  vh - cardH - gap));

        card.style.left = Math.round(left) + 'px';
        card.style.top  = Math.round(top)  + 'px';
    }

    function _autoPlacement(rect, cardH, cardW, vw, vh) {
        const spaceBelow = vh - rect.bottom;
        const spaceAbove = rect.top;
        const spaceRight = vw - rect.right;
        if (spaceBelow >= cardH + 16) return 'bottom';
        if (spaceAbove >= cardH + 16) return 'top';
        if (spaceRight >= cardW + 16) return 'right';
        return 'bottom';
    }

    function _scrollToTarget(el) {
        if (!el) return;
        const rect = el.getBoundingClientRect();
        const vh = window.innerHeight;
        if (rect.top < 80 || rect.bottom > vh - 80) {
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }

    function _renderStep() {
        if (!_tourActive) return;

        _clearHighlight();
        if (_tourCard) { _tourCard.remove(); _tourCard = null; }

        const step = _tourSteps[_tourIndex];
        const total = _tourSteps.length;

        let targetEl = null;
        let targetRect = null;

        if (step.target) {
            targetEl = document.querySelector(step.target);
            if (targetEl) {
                _scrollToTarget(targetEl);
                targetRect = targetEl.getBoundingClientRect();
                targetEl.classList.add('wm-highlight');
                _tourHighlight = targetEl;
            }
        }

        _updateOverlayCutout(targetRect);

        _tourCard = _buildCard(step, _tourIndex, total);
        document.body.appendChild(_tourCard);

        // Position after paint
        requestAnimationFrame(function () {
            _positionCard(_tourCard, targetRect, step.placement);
        });
    }

    function startTour(name, forceReplay) {
        const steps = _tours[name];
        if (!steps || steps.length === 0) {
            console.warn('[AdminWalkMe] No tour registered with name:', name);
            return;
        }

        const seen = storageGet('tour_' + name);
        if (seen && !forceReplay) return;

        _tourActive = true;
        _tourName   = name;
        _tourSteps  = steps;
        _tourIndex  = 0;

        _buildOverlay();
        _renderStep();

        // Keyboard escape
        document.addEventListener('keydown', _onTourKeydown);
    }

    function endTour() {
        _tourActive = false;
        storageSet('tour_' + _tourName, '1');
        document.removeEventListener('keydown', _onTourKeydown);
        _removeOverlay();
        _hideTooltip();
    }

    function _onTourKeydown(e) {
        if (e.key === 'Escape') endTour();
        if (e.key === 'ArrowRight' && _tourIndex < _tourSteps.length - 1) { _tourIndex++; _renderStep(); }
        if (e.key === 'ArrowLeft'  && _tourIndex > 0)                     { _tourIndex--; _renderStep(); }
    }

    /* ─── Start button factory ────────────────────────── */

    /**
     * Creates a "Take a tour" button and inserts it into `containerEl`.
     *   AdminWalkMe.addStartButton(el, 'payment-add', 'Tour this page');
     */
    function addStartButton(containerEl, tourName, label) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'wm-start-btn';
        btn.innerHTML = '<i class="fas fa-map-signs"></i> ' + escHtml(label || 'Tour this page');
        btn.addEventListener('click', function () { startTour(tourName, true); });
        containerEl.appendChild(btn);
        return btn;
    }

    /* ─── Utilities ───────────────────────────────────── */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /* ─── Cleanup on SPA navigation ──────────────────── */
    function _cleanup() {
        if (_tooltipEl && _tooltipEl.parentNode) { _tooltipEl.parentNode.removeChild(_tooltipEl); _tooltipEl = null; }
        if (_tourActive) endTour();
    }

    document.addEventListener('spa:navigate', _cleanup);
    document.addEventListener('page:before-unload', _cleanup);

    /* ─── Public API ──────────────────────────────────── */
    global.AdminWalkMe = {
        registerTour: registerTour,
        startTour:    startTour,
        endTour:      endTour,
        wireTooltips: wireTooltips,
        helpIcon:     helpIcon,
        addStartButton: addStartButton,
    };

}(window));
