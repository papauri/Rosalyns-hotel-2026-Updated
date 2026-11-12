/**
 * End of Day "The day in brief": asks for the brief after the page has loaded (includes/ai/ai-eod-narrative.php).
 * The section is printed hidden and only when the brief may be shown. While asking it shows a quiet placeholder; good text
 * fills it, anything else (no text, an error, a slow answer) removes the section without a trace.
 * Re-run safe: the page calls window.rhEodBrief() after each (in-app) load; a section is asked for once.
 * Only the date is sent; the figures stay on the server.
 */
(function () {
    'use strict';
    function csrf() {
        if (window._rhCsrf) return String(window._rhCsrf);
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? String(m.getAttribute('content') || '') : '';
    }
    function drop(el) {
        if (el && el.parentNode) el.parentNode.removeChild(el);
    }
    window.rhEodBrief = function () {
        var el = document.querySelector('[data-eod-brief]');
        if (!el || el.getAttribute('data-eod-asked') === '1') return;
        el.setAttribute('data-eod-asked', '1');
        if (!window.fetch || !window.FormData) { drop(el); return; }
        el.classList.add('is-loading');
        el.hidden = false;
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 32000) : null;
        var body = new FormData();
        body.append('action', 'get_brief');
        body.append('csrf_token', csrf());
        body.append('date', el.getAttribute('data-date') || '');
        fetch('api/ai-eod-narrative.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (j) {
            var text = j && j.success && typeof j.text === 'string' ? j.text.trim() : '';
            if (!text || !document.body.contains(el)) { drop(el); return; }
            var story = el.querySelector('[data-eod-brief-story]');
            if (!story) { drop(el); return; }
            story.textContent = text;
            var points = Array.isArray(j.points) ? j.points.filter(function (p) { return typeof p === 'string' && p.trim(); }).slice(0, 3) : [];
            var look = el.querySelector('[data-eod-brief-look]');
            var list = look ? look.querySelector('ul') : null;
            if (look && list && points.length) {
                list.textContent = '';
                points.forEach(function (p) {
                    var li = document.createElement('li');
                    li.textContent = p.trim();
                    list.appendChild(li);
                });
                look.hidden = false;
            }
            el.classList.remove('is-loading');
            el.removeAttribute('aria-busy');
        }).catch(function () {
            drop(el); // quiet: the report is complete without it
        }).then(function () {
            if (timer) clearTimeout(timer);
        });
    };
    // Loaded with defer: runs once the page is parsed; the page's own one-line call covers in-app navigation
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.rhEodBrief);
    } else {
        window.rhEodBrief();
    }
})();
