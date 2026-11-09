<?php
/**
 * The History panel (one for the whole admin): a step-by-step list of everything that happened to a booking or a
 * payment, with a Print button. Include it INSIDE the page content (before admin-footer.php) so the admin SPA keeps
 * it. Any element with data-rh-history="booking:123" or "payment:456" opens it; data comes from admin/api/history.php
 * (read-only). Event text is written with textContent only.
 */
if (!defined('ADMIN_ACCESS')) {
    return;
}
?>
<style>
    .rh-hist-dialog, #rhHistoryModal .modal-content.rh-hist-dialog { width: min(920px, calc(100vw - 1.5rem)); max-width: min(920px, calc(100vw - 1.5rem)); max-height: calc(100dvh - 2rem); display: flex; flex-direction: column; }
    .rh-hist-dialog .modal-body { overflow-y: auto; }
    .rh-hist-intro { margin: 0 0 12px; color: var(--color-text-secondary, #5f5a53); font-size: 0.9rem; }
    .rh-hist-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin: 0 0 14px; }
    .rh-hist-fact { border: 1px solid var(--rh-modal-border, #e2dbd2); border-radius: 10px; padding: 8px 10px; background: var(--color-surface-soft, #F4EFE9); }
    .rh-hist-fact dt { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.03em; color: var(--color-text-secondary, #5f5a53); margin: 0; }
    .rh-hist-fact dd { margin: 2px 0 0; font-weight: 600; word-break: break-word; }
    .rh-hist-help { display: block; font-size: 0.72rem; font-weight: 400; color: var(--color-text-secondary, #6b655d); text-transform: none; letter-spacing: 0; margin-top: 2px; }
    .rh-hist-head, .rh-hist-step { display: grid; grid-template-columns: 2.2rem 9.5rem 12rem 1fr; gap: 10px; align-items: start; }
    .rh-hist-head { font-size: 0.78rem; font-weight: 700; color: var(--color-text-primary, #2A2723); border-bottom: 2px solid var(--rh-modal-border, #e2dbd2); padding: 0 0 6px; }
    .rh-hist-list { list-style: none; margin: 0; padding: 0; }
    .rh-hist-step { padding: 10px 0; border-bottom: 1px solid var(--rh-modal-border, #e2dbd2); font-size: 0.9rem; }
    .rh-hist-num { font-weight: 700; color: var(--color-text-secondary, #6b655d); }
    .rh-hist-when { white-space: nowrap; }
    .rh-hist-kind { display: inline-block; font-size: 0.7rem; font-weight: 600; padding: 1px 7px; border-radius: 999px; margin-bottom: 3px;
        background: var(--color-surface-soft, #F4EFE9); border: 1px solid var(--rh-modal-border, #e2dbd2); }
    .rh-hist-kind--payment, .rh-hist-kind--refund, .rh-hist-kind--credit_note { background: #eef6f0; border-color: #b9d8c2; }
    .rh-hist-kind--price { background: #faf6ee; border-color: #e3d3b4; }
    .rh-hist-kind--cancel { background: #faf0ee; border-color: #e2c2ba; }
    .rh-hist-what-text { font-weight: 600; display: block; }
    .rh-hist-detail { white-space: pre-line; color: var(--color-text-secondary, #4f4a43); margin-top: 3px; font-size: 0.85rem; }
    .rh-hist-state { padding: 18px 0; color: var(--color-text-secondary, #5f5a53); }
    .rh-hist-state--error { color: #9b3b2b; }
    .rh-hist-dialog .modal-footer .btn { min-height: 44px; }
    [data-rh-history] { cursor: pointer; }
    /* Every History opener (row buttons, header buttons, menu items) is a 44px target on touch screens. */
    @media (pointer: coarse) {
        [data-rh-history] { min-height: 44px; min-width: 44px; display: inline-flex; align-items: center; justify-content: center; gap: 6px; box-sizing: border-box; }
        .actions-more-menu [data-rh-history], .action-menu [data-rh-history], [role="menu"] [data-rh-history] { display: flex; justify-content: flex-start; width: 100%; }
    }
    @media (max-width: 640px) {
        .rh-hist-head { display: none; }
        .rh-hist-step { grid-template-columns: 1.8rem 1fr; gap: 2px 8px; }
        .rh-hist-when, .rh-hist-who, .rh-hist-what { grid-column: 2; }
        .rh-hist-num { grid-row: 1 / span 3; }
        .rh-hist-when, .rh-hist-who { font-size: 0.8rem; color: var(--color-text-secondary, #5f5a53); }
    }
    #rhHistoryPrint { display: none; }
    @media print {
        body.rh-history-printing > *:not(#rhHistoryPrint) { display: none !important; }
        body.rh-history-printing #rhHistoryPrint { display: block !important; color: #000; font-size: 11pt; }
        #rhHistoryPrint h1 { font-size: 15pt; margin: 0 0 6pt; }
        #rhHistoryPrint .rh-hist-step { break-inside: avoid; }
        #rhHistoryPrint .rh-hist-fact { background: none; }
    }
</style>

<div class="modal-overlay" id="rhHistoryModal" aria-hidden="true" style="display:none;">
    <div class="modal-content rh-hist-dialog" role="dialog" aria-modal="true" aria-labelledby="rhHistoryTitle">
        <div class="modal-header">
            <h3 id="rhHistoryTitle"><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> <span id="rhHistoryTitleText">History</span></h3>
            <button type="button" class="modal-close" aria-label="Close" data-rh-history-close>&times;</button>
        </div>
        <div class="modal-body" id="rhHistoryBody">
            <p class="rh-hist-intro">Every step recorded, oldest first. Times are hotel time.</p>
            <dl class="rh-hist-facts" id="rhHistoryFacts"></dl>
            <div class="rh-hist-head" aria-hidden="true">
                <span>#<span class="rh-hist-help">Step</span></span>
                <span>When<span class="rh-hist-help">Date and time it happened</span></span>
                <span>Who<span class="rh-hist-help">The staff member and their role, the guest, or the system</span></span>
                <span>What happened<span class="rh-hist-help">The step in plain words, with amounts and old → new values</span></span>
            </div>
            <ol class="rh-hist-list" id="rhHistoryList"></ol>
            <div class="rh-hist-state" id="rhHistoryState">Loading…</div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" id="rhHistoryPrintBtn" disabled><i class="fas fa-print" aria-hidden="true"></i> Print</button>
            <button type="button" class="btn btn-primary" data-rh-history-close>Close</button>
        </div>
    </div>
</div>

<script>
(function () {
    var apiUrl = <?php echo json_encode(rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin/x.php'), '/\\') . '/api/history.php', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.rhHistoryApiUrl = apiUrl;
    if (window.rhHistoryInstalled) { return; }
    window.rhHistoryInstalled = true;

    var seq = 0;
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text !== undefined && text !== null) n.textContent = String(text);
        return n;
    }
    function modal() { return document.getElementById('rhHistoryModal'); }
    function setState(text, isError) {
        var s = document.getElementById('rhHistoryState');
        if (!s) return;
        s.textContent = text || '';
        s.style.display = text ? '' : 'none';
        s.className = 'rh-hist-state' + (isError ? ' rh-hist-state--error' : '');
    }
    function close() {
        var m = modal();
        if (!m) return;
        m.style.display = 'none';
        m.classList.remove('active');
        m.setAttribute('aria-hidden', 'true');
        seq++;
    }
    function render(data) {
        var facts = document.getElementById('rhHistoryFacts');
        var list = document.getElementById('rhHistoryList');
        document.getElementById('rhHistoryTitleText').textContent = 'History: ' + (data.title || '');
        (data.facts || []).forEach(function (f) {
            var box = el('div', 'rh-hist-fact');
            var dt = el('dt', '', f.label);
            dt.appendChild(el('span', 'rh-hist-help', f.help));
            box.appendChild(dt);
            box.appendChild(el('dd', '', f.value || '-'));
            facts.appendChild(box);
        });
        var events = data.events || [];
        events.forEach(function (e, i) {
            var li = el('li', 'rh-hist-step');
            li.appendChild(el('span', 'rh-hist-num', (i + 1) + '.'));
            var when = el('span', 'rh-hist-when', e.at_label);
            when.title = e.at;
            li.appendChild(when);
            li.appendChild(el('span', 'rh-hist-who', e.who));
            var what = el('span', 'rh-hist-what');
            what.appendChild(el('span', 'rh-hist-kind rh-hist-kind--' + String(e.kind || '').replace(/[^a-z_]/g, ''), e.kind_label));
            what.appendChild(el('span', 'rh-hist-what-text', e.what));
            if (e.detail) what.appendChild(el('span', 'rh-hist-detail', e.detail));
            li.appendChild(what);
            list.appendChild(li);
        });
        setState(events.length ? '' : 'Nothing has been recorded for this yet.', false);
        document.getElementById('rhHistoryPrintBtn').disabled = !events.length;
    }
    window.rhHistoryOpen = function (type, id) {
        var m = modal();
        if (!m || !id) return;
        var mine = ++seq;
        document.getElementById('rhHistoryTitleText').textContent = 'History';
        document.getElementById('rhHistoryFacts').textContent = '';
        document.getElementById('rhHistoryList').textContent = '';
        document.getElementById('rhHistoryPrintBtn').disabled = true;
        setState('Loading…', false);
        m.style.display = 'flex';
        m.classList.add('active');
        m.setAttribute('aria-hidden', 'false');
        var url = (window.rhHistoryApiUrl || apiUrl) + '?type=' + encodeURIComponent(type) + '&id=' + encodeURIComponent(id);
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().catch(function () { return { success: false }; }); })
            .then(function (j) {
                if (mine !== seq) return;
                if (!j || !j.success) { setState((j && j.error) || 'The history could not be loaded. Please try again.', true); return; }
                render(j);
            })
            .catch(function () { if (mine === seq) setState('The history could not be loaded. Check the connection and try again.', true); });
    };
    function printHistory() {
        var body = document.getElementById('rhHistoryBody');
        if (!body) return;
        var old = document.getElementById('rhHistoryPrint');
        if (old) old.parentNode.removeChild(old);
        var holder = el('div');
        holder.id = 'rhHistoryPrint';
        holder.appendChild(el('h1', '', document.getElementById('rhHistoryTitleText').textContent));
        holder.appendChild(body.cloneNode(true));
        holder.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        document.body.appendChild(holder);
        document.body.classList.add('rh-history-printing');
        var done = function () {
            document.body.classList.remove('rh-history-printing');
            if (holder.parentNode) holder.parentNode.removeChild(holder);
            window.removeEventListener('afterprint', done);
        };
        window.addEventListener('afterprint', done);
        window.print();
    }
    document.addEventListener('click', function (ev) {
        var t = ev.target;
        if (!(t instanceof Element)) return;
        var opener = t.closest('[data-rh-history]');
        if (opener) {
            ev.preventDefault();
            var parts = String(opener.getAttribute('data-rh-history') || '').split(':');
            if ((parts[0] === 'booking' || parts[0] === 'payment') && parseInt(parts[1], 10) > 0) {
                window.rhHistoryOpen(parts[0], parseInt(parts[1], 10));
            }
            return;
        }
        if (t.closest('[data-rh-history-close]')) { close(); return; }
        if (t.closest('#rhHistoryPrintBtn')) { printHistory(); return; }
        if (t.id === 'rhHistoryModal') { close(); }
    });
    document.addEventListener('keydown', function (ev) {
        var m = modal();
        if (ev.key === 'Escape' && m && m.style.display === 'flex') close();
    });
})();
</script>
