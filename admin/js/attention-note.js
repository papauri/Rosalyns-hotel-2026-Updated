/**
 * Dashboard "Needs attention": fills the optional note line after the page has loaded (includes/ai/ai-attention-note.php).
 * The line is printed hidden and only when the note may be shown; any failure or a slow answer leaves it hidden, silently.
 * Re-run safe: the page calls window.rhAttnNote() after each (in-app) load; a line is asked for once.
 */
(function () {
    'use strict';
    function csrf() {
        if (window._rhCsrf) return String(window._rhCsrf);
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? String(m.getAttribute('content') || '') : '';
    }
    window.rhAttnNote = function () {
        var el = document.querySelector('[data-attn-note]');
        if (!el || el.getAttribute('data-attn-asked') === '1' || !window.fetch || !window.FormData) return;
        el.setAttribute('data-attn-asked', '1');
        var ctrl = window.AbortController ? new AbortController() : null;
        var timer = ctrl ? setTimeout(function () { ctrl.abort(); }, 15000) : null;
        var body = new FormData();
        body.append('action', 'get_note');
        body.append('csrf_token', csrf());
        fetch('api/ai-attention-note.php', {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            signal: ctrl ? ctrl.signal : undefined
        }).then(function (r) {
            return r.ok ? r.json() : null;
        }).then(function (j) {
            var note = j && j.success && typeof j.note === 'string' ? j.note.trim() : '';
            if (note && document.body.contains(el)) {
                el.textContent = note;
                el.hidden = false;
            }
        }).catch(function () {
            // quiet: the panel works the same without the note
        }).then(function () {
            if (timer) clearTimeout(timer);
        });
    };
    // Loaded with defer: runs once the page is parsed; the page's own one-line call covers in-app navigation
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', window.rhAttnNote);
    } else {
        window.rhAttnNote();
    }
})();
