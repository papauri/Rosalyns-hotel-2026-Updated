/* "i" popup: how a booking total is worked out.
   Buttons carry their figures in data-rh-breakdown (JSON, built by rh_booking_total_breakdown()); the popup is built
   with textContent only. Everything is delegated on document, so it keeps working after admin-spa.js swaps the page. */
(function () {
    'use strict';
    if (window.__rhTotalInfo) return;
    window.__rhTotalInfo = true;

    var pop = null;
    var current = null; // button the popup belongs to
    var sticky = false; // opened by click/tap (stays until Esc / outside / second click)
    var hideTimer = 0;
    var canHover = !!(window.matchMedia && window.matchMedia('(hover: hover)').matches);

    function esc(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** Button markup for screens that build their rows in JavaScript (breakdown = object from the server). */
    window.rhTotalInfoHtml = function (breakdown) {
        if (!breakdown || typeof breakdown !== 'object') return '';
        return '<button type="button" class="rh-info-btn" aria-label="How this total is worked out" aria-haspopup="dialog" aria-expanded="false" data-rh-breakdown="' +
            esc(JSON.stringify(breakdown)) + '"><span aria-hidden="true">i</span></button>';
    };

    function ensurePop() {
        if (pop && document.body.contains(pop)) return pop;
        pop = document.createElement('div');
        pop.className = 'rh-info-pop';
        pop.id = 'rhTotalInfoPop';
        pop.setAttribute('role', 'dialog');
        pop.tabIndex = -1;
        pop.setAttribute('aria-label', 'How this total is worked out');
        pop.hidden = true;
        pop.addEventListener('mouseenter', function () { clearTimeout(hideTimer); });
        pop.addEventListener('mouseleave', function () { if (!sticky) scheduleHide(); });
        document.body.appendChild(pop);
        return pop;
    }

    function build(data) {
        var el = ensurePop();
        while (el.firstChild) el.removeChild(el.firstChild);
        var h = document.createElement('p');
        h.className = 'rh-info-pop__title';
        h.textContent = (data && data.title) || 'How this total is worked out';
        el.appendChild(h);
        var lines = data && Array.isArray(data.lines) ? data.lines : [];
        if (lines.length) {
            var ul = document.createElement('ul');
            ul.className = 'rh-info-pop__list';
            lines.forEach(function (ln) {
                var kind = ['add', 'note', 'total', 'minus'].indexOf(ln.kind) >= 0 ? ln.kind : 'add';
                var li = document.createElement('li');
                li.className = 'rh-info-pop__row rh-info-pop__row--' + kind;
                var lab = document.createElement('span');
                lab.className = 'rh-info-pop__label';
                lab.textContent = ln.label || '';
                if (ln.hint) {
                    var hint = document.createElement('small');
                    hint.className = 'rh-info-pop__hint';
                    hint.textContent = ln.hint;
                    lab.appendChild(hint);
                }
                var val = document.createElement('span');
                val.className = 'rh-info-pop__value';
                val.textContent = ln.value || '';
                li.appendChild(lab);
                li.appendChild(val);
                ul.appendChild(li);
            });
            el.appendChild(ul);
        }
        if (data && data.note) {
            var n = document.createElement('p');
            n.className = 'rh-info-pop__note';
            n.textContent = data.note;
            el.appendChild(n);
        }
        return el;
    }

    function place() {
        if (!pop || !current || !document.body.contains(current)) return;
        var r = current.getBoundingClientRect();
        var pw = pop.offsetWidth;
        var ph = pop.offsetHeight;
        var vw = document.documentElement.clientWidth;
        var vh = window.innerHeight;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - pw / 2), Math.max(8, vw - pw - 8));
        var top = r.bottom + 8;
        if (top + ph > vh - 8 && r.top - ph - 8 >= 8) top = r.top - ph - 8;
        top = Math.max(8, Math.min(top, vh - ph - 8));
        pop.style.left = left + 'px';
        pop.style.top = top + 'px';
    }

    function show(btn, makeSticky) {
        clearTimeout(hideTimer);
        var data = null;
        try { data = JSON.parse(btn.getAttribute('data-rh-breakdown') || 'null'); } catch (e) { data = null; }
        if (!data) data = { ok: false, lines: [], note: 'Breakdown not available for this older booking.' };
        if (current && current !== btn) current.setAttribute('aria-expanded', 'false');
        build(data);
        pop.hidden = false;
        current = btn;
        sticky = !!makeSticky;
        btn.setAttribute('aria-expanded', 'true');
        btn.setAttribute('aria-controls', 'rhTotalInfoPop');
        place();
    }

    function hide(returnFocus) {
        clearTimeout(hideTimer);
        if (pop) pop.hidden = true;
        if (current) {
            current.setAttribute('aria-expanded', 'false');
            if (returnFocus && document.body.contains(current)) current.focus();
        }
        current = null;
        sticky = false;
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(function () { if (!sticky) hide(false); }, 180);
    }

    function btnOf(target) {
        return target && target.closest ? target.closest('.rh-info-btn[data-rh-breakdown]') : null;
    }

    document.addEventListener('click', function (e) {
        var btn = btnOf(e.target);
        if (btn) {
            e.preventDefault();
            e.stopPropagation(); // the button may sit inside a clickable row/card
            if (current === btn && sticky) hide(false);
            else show(btn, true);
            return;
        }
        if (current && pop && !pop.contains(e.target)) hide(false);
    }, true);

    document.addEventListener('mouseover', function (e) {
        if (!canHover) return;
        var btn = btnOf(e.target);
        if (btn && !(current === btn && sticky)) show(btn, false);
        else if (btn) clearTimeout(hideTimer);
    });

    document.addEventListener('mouseout', function (e) {
        if (!canHover || sticky) return;
        var btn = btnOf(e.target);
        if (btn && !(pop && e.relatedTarget && pop.contains(e.relatedTarget))) scheduleHide();
    });

    document.addEventListener('focusin', function (e) {
        var btn = btnOf(e.target);
        if (btn && !sticky && btn.matches(':focus-visible')) show(btn, false);
    });

    // Focus leaving the button/popup (Tab away) closes it, click-opened or not.
    document.addEventListener('focusout', function (e) {
        if (!current || !pop) return;
        var inside = e.target === current || pop.contains(e.target);
        if (!inside) return;
        var to = e.relatedTarget;
        if (to && (to === current || pop.contains(to))) return;
        hide(false);
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && current) {
            hide(true);
        }
    });

    window.addEventListener('resize', function () { if (current) place(); });
    window.addEventListener('scroll', function () {
        if (!current) return;
        if (!document.body.contains(current)) hide(false);
        else place();
    }, true);
    // admin-spa.js swaps the page in place: drop a popup whose button is gone.
    document.addEventListener('rh:content-updated', function () { if (current) hide(false); });
})();
