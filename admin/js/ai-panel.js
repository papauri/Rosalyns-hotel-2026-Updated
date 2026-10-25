/**
 * The Assistant panel (admin/includes/ai-panel.php). Lives outside #rh-admin-page, so it survives
 * in-app navigation; binds once. Replies are ESCAPED first, then a small markdown subset is applied
 * (bold, code, lists, paragraphs) - model text is never put into the page raw. Links from the server
 * are checked against admin screens / staff guides and built with DOM methods. The daily briefing is laid
 * out like a short document (headline figure, what it is, why, where from, one link). While it works the
 * panel shows one plain line naming the real step, read from the server (no animated dots).
 * Action cards (Phase 4): a card is a document (old -> new, total, warnings in red) with one Confirm and one
 * Cancel. Confirm asks api/ai-action.php for the replay, posts it to the EXISTING screen endpoint with this
 * session's security token and the X-Requested-With header, then reports the screen's answer back.
 */
(function () {
    'use strict';
    if (window.__rhAiPanelBound) { return; }
    var panel = document.getElementById('rhAiPanel');
    var launch = document.getElementById('rhAiLaunch');
    if (!panel || !launch) { return; }
    window.__rhAiPanelBound = true;

    var endpoint = panel.getAttribute('data-endpoint') || 'api/ai-chat.php';
    var actEndpoint = panel.getAttribute('data-action-endpoint') || 'api/ai-action.php';
    var stream = document.getElementById('rhAiStream');
    var chipsBox = document.getElementById('rhAiChips');
    var form = document.getElementById('rhAiForm');
    var input = document.getElementById('rhAiInput');
    var sendBtn = form.querySelector('.rh-ai-send');
    var stopBtn = form.querySelector('.rh-ai-stop');
    var KEY_OPEN = 'rh_ai_open';
    var KEY_CONV = 'rh_ai_conv';
    var state = { conv: 0, busy: false, ctrl: null, loaded: false, poll: null };

    function csrf() {
        return panel.getAttribute('data-csrf') || window._rhCsrf || ((document.querySelector('meta[name="csrf-token"]') || {}).content) || '';
    }
    function store(k, v) { try { if (v === null) { sessionStorage.removeItem(k); } else { sessionStorage.setItem(k, String(v)); } } catch (e) { /* private mode */ } }
    function read(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } }

    function currentPage() {
        var p = (window.location.pathname || '').split('/').pop() || 'dashboard.php';
        return /^[a-z0-9\-_]+\.php$/i.test(p) ? p : 'dashboard.php';
    }
    function currentBookingId() {
        var q = new URLSearchParams(window.location.search);
        var page = currentPage();
        var id = q.get('booking_id') || (/booking/.test(page) ? q.get('id') : '');
        return /^\d+$/.test(id || '') ? id : '';
    }

    function post(data, signal, url) {
        var fd = new FormData();
        fd.append('csrf_token', csrf());
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(url || endpoint, { method: 'POST', body: fd, credentials: 'same-origin', signal: signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) {
                return r.json().catch(function () { return { success: false, error: 'The Assistant sent an answer that could not be read.' }; })
                    .then(function (j) { if (r.status === 404) { j = { success: false, error: 'The Assistant is switched off.' }; } return j; });
            });
    }

    // ── Safe formatting: escape, then a small markdown subset ────────────────
    function esc(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function inline(s) {
        return s.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    }
    function formatReply(text) {
        var lines = esc(text || '').split(/\r?\n/);
        var html = '';
        var list = '';
        var para = [];
        function flushPara() { if (para.length) { html += '<p>' + para.map(inline).join('<br>') + '</p>'; para = []; } }
        function closeList() { if (list) { html += '</' + list + '>'; list = ''; } }
        lines.forEach(function (line) {
            var ul = line.match(/^\s*[-*•]\s+(.*)$/);
            var ol = line.match(/^\s*\d+[.)]\s+(.*)$/);
            var h = line.match(/^\s*#{1,4}\s+(.*)$/);
            if (ul || ol) {
                flushPara();
                var want = ul ? 'ul' : 'ol';
                if (list !== want) { closeList(); html += '<' + want + '>'; list = want; }
                html += '<li>' + inline((ul || ol)[1]) + '</li>';
            } else if (h) {
                flushPara(); closeList();
                html += '<p><strong>' + inline(h[1]) + '</strong></p>';
            } else if (line.trim() === '') {
                flushPara(); closeList();
            } else {
                closeList();
                para.push(line);
            }
        });
        flushPara(); closeList();
        return html;
    }
    function safeUrl(u) {
        u = String(u || '');
        if (/^\.\.\/docs\/guides\/[A-Za-z0-9\-_.]+(#[A-Za-z0-9\-_]+)?$/.test(u)) { return { url: u, guide: true }; }
        if (/^[a-z0-9\-_]+\.php(\?[A-Za-z0-9\-_.=&%+]*)?$/i.test(u)) { return { url: u, guide: false }; }
        return null;
    }

    // ── Rendering ────────────────────────────────────────────────────────────
    function scrollDown() { stream.scrollTop = stream.scrollHeight; }
    function addBubble(role, text, extra) {
        extra = extra || {};
        var b = document.createElement('div');
        b.className = 'rh-ai-msg rh-ai-msg--' + (role === 'user' ? 'user' : 'bot');
        if (role === 'user') {
            b.textContent = text;
        } else {
            b.innerHTML = formatReply(text); // escaped above, then formatted
            if (extra.items && extra.items.length) { b.appendChild(briefing(extra.items)); }
            (extra.cards || []).forEach(function (c) { b.appendChild(card(c)); });
            (extra.links || []).forEach(function (l) {
                var s = safeUrl(l && l.url);
                if (!s) { return; }
                var a = document.createElement('a');
                a.className = 'btn btn-secondary btn-sm rh-ai-link';
                a.href = s.url;
                if (s.guide) { a.target = '_blank'; a.rel = 'noopener'; }
                var i = document.createElement('i');
                i.className = 'fas ' + (s.guide ? 'fa-book-open' : 'fa-arrow-right');
                i.setAttribute('aria-hidden', 'true');
                a.appendChild(i);
                a.appendChild(document.createTextNode(' ' + String((l && l.label) || 'Open')));
                b.appendChild(a);
            });
            if (extra.notice) {
                var n = document.createElement('p');
                n.className = 'rh-ai-notice';
                n.textContent = extra.notice;
                b.appendChild(n);
            }
        }
        stream.appendChild(b);
        scrollDown();
        return b;
    }
    function addError(text) {
        var b = document.createElement('div');
        b.className = 'rh-ai-msg rh-ai-msg--error';
        b.setAttribute('role', 'alert');
        b.textContent = text;
        stream.appendChild(b);
        scrollDown();
    }
    function setChips(list) {
        chipsBox.textContent = '';
        (list || []).slice(0, 4).forEach(function (t) {
            var c = document.createElement('button');
            c.type = 'button';
            c.className = 'rh-ai-chip';
            c.textContent = String(t);
            c.addEventListener('click', function () { send(String(t)); });
            chipsBox.appendChild(c);
        });
    }
    function welcome() {
        stream.textContent = '';
        var w = document.createElement('div');
        w.className = 'rh-ai-welcome';
        var h = document.createElement('p');
        h.innerHTML = '<strong>Hello.</strong>';
        var t = document.createElement('p');
        t.textContent = 'Ask me about arrivals, departures, rooms, a booking, a price or how to do something in the system. Tap a suggestion below to start.';
        w.appendChild(h); w.appendChild(t);
        stream.appendChild(w);
    }
    function loadSuggestions() {
        post({ action: 'suggestions', page: currentPage() }).then(function (j) {
            if (j && j.success) { setChips(j.chips); }
        }).catch(function () { /* chips are optional */ });
    }
    function el(tag, cls, text) {
        var e = document.createElement(tag);
        if (cls) { e.className = cls; }
        if (text !== undefined && text !== null && text !== '') { e.textContent = String(text); }
        return e;
    }
    // The briefing as a document: one row per point (figure | title, cause, lines, source, link). Text only, no HTML.
    function briefing(items) {
        var box = el('div', 'rh-ai-brief');
        box.setAttribute('role', 'list');
        items.forEach(function (it) {
            var row = el('div', 'rh-ai-brief__item' + (it.kind === 'money' ? ' rh-ai-brief__item--money' : ''));
            row.setAttribute('role', 'listitem');
            row.appendChild(el('div', 'rh-ai-brief__figure', it.figure || ''));
            row.appendChild(el('p', 'rh-ai-brief__title', it.title || ''));
            row.appendChild(el('p', 'rh-ai-brief__detail', it.detail || ''));
            if (it.lines && it.lines.length) {
                var ul = el('ul', 'rh-ai-brief__lines');
                it.lines.forEach(function (l, i) {
                    var li = el('li', '', l);
                    var act = it.acts && it.acts[i];
                    if (act && act.key && act.booking_id) {
                        var tap = el('button', 'rh-ai-brief__act', act.label || 'Prepare');
                        tap.type = 'button';
                        tap.addEventListener('click', function () { prepare(act, tap, row); });
                        li.appendChild(document.createTextNode(' '));
                        li.appendChild(tap);
                    }
                    ul.appendChild(li);
                });
                row.appendChild(ul);
            }
            if (it.source) { row.appendChild(el('p', 'rh-ai-brief__source', it.source)); }
            var s = it.link && safeUrl(it.link.url);
            if (s && !s.guide) {
                var a = el('a', 'btn btn-secondary btn-sm rh-ai-brief__link');
                a.href = s.url;
                var i = el('i', 'fas fa-arrow-right');
                i.setAttribute('aria-hidden', 'true');
                a.appendChild(i);
                a.appendChild(document.createTextNode(' ' + String(it.link.label || 'Open')));
                row.appendChild(a);
            }
            box.appendChild(row);
        });
        return box;
    }
    // ── Action cards ─────────────────────────────────────────────────────────
    function safePage(u) { return /^[a-z0-9\-]+\.php$/i.test(String(u || '')); }
    // A briefing point's one-tap fix: prepares the card (no model call); Confirm on the card is the second tap.
    function prepare(act, btn, row) {
        btn.disabled = true;
        post({ action: 'propose', key: act.key, booking_id: act.booking_id, conversation_id: state.conv || 0, page: currentPage() }, undefined, actEndpoint).then(function (j) {
            btn.disabled = false;
            if (!j || !j.success) { row.appendChild(el('p', 'rh-ai-card__warn', (j && j.error) || 'That could not be prepared.')); return; }
            if (j.conversation_id && !state.conv) { state.conv = j.conversation_id; store(KEY_CONV, state.conv); }
            btn.hidden = true;
            row.appendChild(card(j.card));
            scrollDown();
        }).catch(function () { btn.disabled = false; });
    }
    function cardState(c) {
        return {
            executed: c.message || 'Done.',
            failed: c.message || 'Not done.',
            cancelled: 'Cancelled. Nothing was changed.',
            expired: c.message || 'This card has expired. Ask again for a fresh one.',
            confirmed: 'Sent - waiting for the screen to answer.',
            handoff: ''
        }[c.status] || '';
    }
    function card(c) {
        var box = el('div', 'rh-ai-card');
        box.setAttribute('data-card-id', String(c.id));
        box.setAttribute('role', 'group');
        box.setAttribute('aria-label', c.title || 'Action card');
        fillCard(box, c);
        return box;
    }
    function fillCard(box, c) {
        box.textContent = '';
        box.className = 'rh-ai-card' + (c.danger ? ' rh-ai-card--danger' : '') + ' rh-ai-card--' + c.status;
        box.appendChild(el('p', 'rh-ai-card__title', c.title));
        if (c.help) { box.appendChild(el('p', 'rh-ai-card__help', c.help)); }
        var dl = el('dl', 'rh-ai-card__rows');
        (c.rows || []).forEach(function (r) {
            var dd = el('dd', r.tone ? 'rh-ai-card__val--' + r.tone : '');
            if (r.old !== undefined && r.old !== null && r.old !== '') {
                dd.appendChild(el('span', 'rh-ai-card__old', r.old));
                dd.appendChild(el('span', 'rh-ai-card__arrow', ' to '));
            }
            dd.appendChild(el('span', 'rh-ai-card__new', r['new']));
            dl.appendChild(el('dt', '', r.label));
            dl.appendChild(dd);
        });
        box.appendChild(dl);
        (c.warn || []).forEach(function (w) { var p = el('p', 'rh-ai-card__warn', w); p.setAttribute('role', 'alert'); box.appendChild(p); });
        (c.notes || []).forEach(function (n) { box.appendChild(el('p', 'rh-ai-card__note', n)); });
        var live = c.status === 'proposed';
        var inputs = [];
        if (live) {
            (c.fields || []).forEach(function (f) {
                var id = 'rhAiF' + c.id + '_' + f.name;
                var lab = el('label', 'rh-ai-card__label', f.label);
                lab.setAttribute('for', id);
                var inp = document.createElement('input');
                inp.id = id; inp.name = f.name; inp.type = ['email', 'tel'].indexOf(f.type) >= 0 ? f.type : 'text';
                inp.value = f.value || ''; inp.className = 'rh-ai-card__input'; inp.maxLength = 200;
                if (f.required) { inp.required = true; }
                box.appendChild(lab); box.appendChild(inp);
                inputs.push(inp);
            });
            (c.choices || []).forEach(function (f) {
                var id = 'rhAiC' + c.id + '_' + f.name;
                var lab = el('label', 'rh-ai-card__label', f.label);
                lab.setAttribute('for', id);
                var sel = document.createElement('select');
                sel.id = id; sel.name = f.name; sel.className = 'rh-ai-card__input';
                (f.options || []).forEach(function (o) {
                    var op = document.createElement('option');
                    op.value = String(o[0]); op.textContent = String(o[1]);
                    if (String(o[0]) === String(f.value)) { op.selected = true; }
                    sel.appendChild(op);
                });
                box.appendChild(lab); box.appendChild(sel);
                inputs.push(sel);
            });
        }
        var st = el('p', 'rh-ai-card__state', live ? '' : cardState(c));
        st.setAttribute('role', 'status');
        box.appendChild(st);
        var btns = el('div', 'rh-ai-card__btns');
        var h = c.handoff;
        if (h && safeUrl(h.url) && !safeUrl(h.url).guide) {
            var a = el('a', 'btn btn-secondary rh-ai-card__btn');
            a.href = h.url;
            var ic = el('i', 'fas fa-arrow-right'); ic.setAttribute('aria-hidden', 'true');
            a.appendChild(ic); a.appendChild(document.createTextNode(' ' + (h.label || 'Open')));
            if (h.prefill) {
                a.addEventListener('click', function () { try { sessionStorage.setItem('rh_ai_prefill', JSON.stringify({ page: String(h.url).split('?')[0], fields: h.prefill })); } catch (e) { /* private mode */ } });
            }
            btns.appendChild(a);
        }
        if (live && c.confirm) {
            var ok = el('button', 'btn ' + (c.danger ? 'btn-danger' : 'btn-primary') + ' rh-ai-card__btn', c.confirm);
            ok.type = 'button';
            var no = el('button', 'btn btn-secondary rh-ai-card__btn', 'Cancel');
            no.type = 'button';
            var check = function () { ok.disabled = inputs.some(function (i) { return i.required && !String(i.value).trim(); }); };
            inputs.forEach(function (i) { i.addEventListener('input', check); });
            check();
            ok.addEventListener('click', function () { confirmCard(box, c, inputs, ok, no, st); });
            no.addEventListener('click', function () {
                ok.disabled = true; no.disabled = true;
                post({ action: 'cancel', card_id: c.id }, undefined, actEndpoint).then(function (j) { if (j && j.card) { fillCard(box, j.card); } });
            });
            btns.appendChild(ok); btns.appendChild(no);
        }
        if (btns.childNodes.length) { box.appendChild(btns); }
    }
    function confirmCard(box, c, inputs, ok, no, st) {
        var fields = {};
        inputs.forEach(function (i) { fields[i.name] = i.value; });
        ok.disabled = true; no.disabled = true;
        st.textContent = 'Checking the card again...';
        post({ action: 'confirm', card_id: c.id, fields: JSON.stringify(fields) }, undefined, actEndpoint).then(function (j) {
            if (!j || !j.success) {
                if (j && j.card) { fillCard(box, j.card); }
                if (j && j.new_card) { box.parentNode.insertBefore(card(j.new_card), box.nextSibling); }
                var s2 = box.querySelector('.rh-ai-card__state');
                if (s2) { s2.textContent = (j && j.error) || 'That could not be confirmed.'; }
                if (!j || !j.card) { ok.disabled = false; no.disabled = false; }
                return null;
            }
            st.textContent = 'Saving on the screen...';
            return replay(j.steps || []).then(function (res) {
                return post({ action: 'report', card_id: c.id, result: JSON.stringify(res.json || null), guard_failed: res.guard ? '1' : '0' }, undefined, actEndpoint);
            }).then(function (r) {
                if (r && r.card) { fillCard(box, r.card); } else { st.textContent = (r && r.error) || 'The result could not be recorded. Check the screen.'; }
                if (r && r.next_card) { box.parentNode.insertBefore(card(r.next_card), box.nextSibling); }
                scrollDown();
                document.dispatchEvent(new CustomEvent('rh:assistant-changed', { detail: { card: c.id } }));
                return null;
            });
        }).catch(function () { st.textContent = 'Could not reach the server. Check the screen before trying again.'; });
    }
    // Post each step to the existing screen, as that screen's own buttons do (same session, token and header).
    function replay(steps) {
        var i = 0;
        function next(last) {
            if (i >= steps.length) { return Promise.resolve({ json: last }); }
            var s = steps[i++];
            if (!safePage(s.url)) { return Promise.resolve({ json: { success: false, message: 'That screen is not allowed.' } }); }
            var fd = new FormData();
            fd.append('csrf_token', csrf());
            (s.fields || []).forEach(function (f) { fd.append(String(f[0]), String(f[1])); });
            return fetch(s.url, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                    var j = null;
                    try { j = JSON.parse(t); } catch (e) { j = null; }
                    if (s.guard === 'assess_clear') {
                        var clear = j && j.success && !(j.settlement && j.settlement.needed) && (parseFloat(j.balance_due || 0) <= 0.01);
                        return clear ? next(j) : { json: j, guard: true };
                    }
                    if (!j || j.success === false) { return { json: j }; }
                    return next(j);
                });
        }
        return next(null);
    }
    // "Open in Create Booking": fill the form fields the card already knows (the person picks the rooms there).
    function applyPrefill() {
        var raw = null;
        try { raw = sessionStorage.getItem('rh_ai_prefill'); } catch (e) { raw = null; }
        if (!raw) { return; }
        var p = null;
        try { p = JSON.parse(raw); } catch (e) { p = null; }
        if (!p || p.page !== currentPage()) { return; }
        var probe = document.querySelector('form [name="guest_name"]');
        var form = probe && probe.form;
        if (!form) { return; }
        try { sessionStorage.removeItem('rh_ai_prefill'); } catch (e) { /* ignore */ }
        Object.keys(p.fields || {}).forEach(function (k) {
            var f = form.querySelector('[name="' + k.replace(/[^a-z_]/gi, '') + '"]');
            if (f) {
                f.value = String(p.fields[k]);
                f.dispatchEvent(new Event('input', { bubbles: true }));
                f.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    // One calm line naming the step the server is on ("Checking today's departures...").
    function status(on, text) {
        var t = document.getElementById('rhAiStatus');
        if (on && !t) {
            t = el('p', 'rh-ai-status');
            t.id = 'rhAiStatus';
            t.setAttribute('role', 'status');
            stream.appendChild(t);
        }
        if (on) {
            t.textContent = text || 'Reading your question...';
            scrollDown();
        } else if (t) {
            t.remove();
        }
    }
    function pollStatus() {
        if (!state.busy) { return; }
        post({ action: 'status' }).then(function (j) {
            if (state.busy && j && j.success && j.step) { status(true, j.step); }
        }).catch(function () { /* the status line is optional */ });
    }
    function busy(on, first) {
        state.busy = on;
        sendBtn.hidden = on;
        stopBtn.hidden = !on;
        input.disabled = on;
        status(on, first);
        if (state.poll) { clearInterval(state.poll); state.poll = null; }
        if (on) { state.poll = setInterval(pollStatus, 1200); }
    }

    // ── Views ────────────────────────────────────────────────────────────────
    function show(view) {
        panel.querySelectorAll('.rh-ai-view').forEach(function (v) { v.hidden = v.getAttribute('data-view') !== view; });
        if (view === 'chats') { loadChats(); }
        if (view === 'prefs') { loadPrefs(); }
        if (view === 'chat') { setTimeout(function () { input.focus(); }, 0); }
    }
    function loadChats() {
        var ul = document.getElementById('rhAiChatList');
        ul.textContent = '';
        post({ action: 'list' }).then(function (j) {
            if (!j || !j.success) { return; }
            if (!j.conversations.length) {
                var li = document.createElement('li');
                li.className = 'rh-ai-help';
                li.textContent = 'No chats yet.';
                ul.appendChild(li);
                return;
            }
            j.conversations.forEach(function (c) {
                var li = document.createElement('li');
                var open = document.createElement('button');
                open.type = 'button';
                open.className = 'rh-ai-chatlist__open';
                open.textContent = c.title;
                open.addEventListener('click', function () { openConversation(c.id); show('chat'); });
                var arch = document.createElement('button');
                arch.type = 'button';
                arch.className = 'rh-ai-icon';
                arch.setAttribute('aria-label', 'Remove this chat from the list');
                arch.title = 'Remove from list';
                arch.innerHTML = '<i class="fas fa-box-archive" aria-hidden="true"></i>';
                arch.addEventListener('click', function () {
                    post({ action: 'archive', conversation_id: c.id }).then(function () {
                        if (state.conv === c.id) { newChat(); }
                        loadChats();
                    });
                });
                li.appendChild(open); li.appendChild(arch);
                ul.appendChild(li);
            });
        });
    }
    function loadPrefs() {
        post({ action: 'prefs_get' }).then(function (j) {
            if (!j || !j.success) { return; }
            document.getElementById('rhAiPrefLength').value = j.prefs.length;
            document.getElementById('rhAiPrefTone').value = j.prefs.tone;
            document.getElementById('rhAiPrefLang').value = j.prefs.language;
            document.getElementById('rhAiPrefBrief').value = j.prefs.briefing || 'on';
        });
    }
    function openConversation(id) {
        post({ action: 'load', conversation_id: id }).then(function (j) {
            if (!j || !j.success) { newChat(); return; }
            state.conv = j.conversation_id;
            store(KEY_CONV, state.conv);
            stream.textContent = '';
            if (!j.messages.length) { welcome(); }
            j.messages.forEach(function (m) { addBubble(m.role, m.text, { links: m.links, notice: m.notice, items: m.items, cards: m.cards }); });
            setChips([]);
            if (!j.messages.length) { loadSuggestions(); }
        });
    }
    // First open of the day: the server shows the briefing only when the person's preference is on and it
    // has not been shown today; otherwise it answers shown:false and nothing changes.
    function autoBriefing() {
        if (state.busy) { return; }
        busy(true, 'Going through today...');
        post({ action: 'briefing', auto: '1' }).then(function (j) {
            busy(false);
            if (!j || !j.success || !j.shown) { return; }
            state.conv = j.conversation_id;
            store(KEY_CONV, state.conv);
            stream.textContent = '';
            addBubble('assistant', j.reply, { items: j.items });
            setChips(j.chips || []);
        }).catch(function () { busy(false); });
    }
    function newChat() {
        state.conv = 0;
        store(KEY_CONV, null);
        welcome();
        loadSuggestions();
        show('chat');
    }

    // ── Sending ──────────────────────────────────────────────────────────────
    function send(text) {
        text = String(text || '').trim();
        if (!text || state.busy) { return; }
        var w = stream.querySelector('.rh-ai-welcome');
        if (w) { w.remove(); }
        addBubble('user', text);
        setChips([]);
        input.value = '';
        autosize();
        state.ctrl = window.AbortController ? new AbortController() : null;
        busy(true);
        post({ action: 'send', message: text, conversation_id: state.conv || 0, page: currentPage(), booking_id: currentBookingId() }, state.ctrl ? state.ctrl.signal : undefined)
            .then(function (j) {
                busy(false);
                if (j && j.conversation_id) { state.conv = j.conversation_id; store(KEY_CONV, state.conv); }
                if (!j || !j.success) { addError((j && j.error) || 'The Assistant could not answer just now.'); return; }
                addBubble('assistant', j.reply, { links: j.links, notice: j.notice, items: j.items, cards: j.cards });
                setChips(j.chips || []);
            })
            .catch(function (e) {
                busy(false);
                addError(e && e.name === 'AbortError' ? 'Stopped.' : 'Could not reach the Assistant. Check the connection and try again.');
            })
            .finally(function () { setTimeout(function () { input.focus(); }, 0); });
    }
    function autosize() {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 140) + 'px';
    }

    // ── Open / close ─────────────────────────────────────────────────────────
    function open() {
        panel.hidden = false;
        launch.setAttribute('aria-expanded', 'true');
        document.documentElement.classList.add('rh-ai-is-open');
        store(KEY_OPEN, '1');
        if (!state.loaded) {
            state.loaded = true;
            var saved = parseInt(read(KEY_CONV) || '0', 10);
            if (saved > 0) { openConversation(saved); } else { welcome(); loadSuggestions(); }
            autoBriefing();
        }
        show('chat');
    }
    function close() {
        panel.hidden = true;
        launch.setAttribute('aria-expanded', 'false');
        document.documentElement.classList.remove('rh-ai-is-open');
        store(KEY_OPEN, null);
        launch.focus();
    }

    launch.addEventListener('click', function () { if (panel.hidden) { open(); } else { close(); } });
    panel.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ai]');
        if (!b) { return; }
        var what = b.getAttribute('data-ai');
        if (what === 'close') { close(); }
        else if (what === 'new') { newChat(); }
        else if (what === 'chats') { show('chats'); }
        else if (what === 'prefs') { show('prefs'); }
        else if (what === 'back') { show('chat'); }
        else if (what === 'stop' && state.ctrl) { state.ctrl.abort(); }
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
    });
    input.addEventListener('input', autosize);
    document.getElementById('rhAiPrefs').addEventListener('submit', function (e) {
        e.preventDefault();
        var val = function (id) { return document.getElementById(id).value; }; // (form.length is the control count, not the field)
        post({ action: 'prefs_set', length: val('rhAiPrefLength'), tone: val('rhAiPrefTone'), language: val('rhAiPrefLang'), briefing: val('rhAiPrefBrief') }).then(function () { show('chat'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !panel.hidden) { close(); }
    });
    // In-app navigation: the panel stays; starter chips follow the new screen when the chat is empty.
    document.addEventListener('rh:content-updated', function () {
        if (!panel.hidden && !state.conv && !state.busy) { loadSuggestions(); }
        applyPrefill();
    });
    applyPrefill();

    if (read(KEY_OPEN) === '1') { open(); }
})();
