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
 * Feedback (Phase 4b): under each reply "Was this right?" with Helpful / Not right; Not right opens a short box
 * for what it should have said. It only changes the Assistant's notes (facts and wording), never what it may do.
 * The check-out guard uses the server's money tolerance (data-tolerance), not a number of its own.
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
    var state = { conv: 0, busy: false, ctrl: null, loaded: false, poll: null, open: false };
    var feedbackOn = panel.getAttribute('data-feedback') === '1';
    var tolerance = parseFloat(panel.getAttribute('data-tolerance'));
    if (!(tolerance >= 0)) { tolerance = 0.01; }

    function csrf() {
        return panel.getAttribute('data-csrf') || window._rhCsrf || ((document.querySelector('meta[name="csrf-token"]') || {}).content) || '';
    }
    // Phones and tablets: never focus the box ourselves, or the keyboard pops up and
    // covers the messages. Staff tap the box when they want to type.
    var touchOnly = !!(window.matchMedia && window.matchMedia('(hover: none) and (pointer: coarse)').matches);
    function focusLater(el) { if (el && !touchOnly) { setTimeout(function () { el.focus(); }, 0); } }
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
                    .then(function (j) {
                        if (r.status === 404) { j = { success: false, error: 'The Assistant is switched off.' }; }
                        // Idle responses, including quiet alert/status polls, wipe the visible chat. A password
                        // lockout only stops risky confirms; it must not look like an idle lock or hide the chat.
                        if (j && j.locked && !j.password_locked && data.action !== 'unlock') { lockPanel(); }
                        return j;
                    });
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
            if (feedbackOn && extra.messageId) { b.appendChild(feedbackRow(extra.messageId, extra.rated || 0)); }
        }
        stream.appendChild(b);
        scrollDown();
        return b;
    }
    // "Was this right?" - Helpful / Not right, and an optional correction (facts and wording only).
    function feedbackRow(id, rated) {
        var row = el('div', 'rh-ai-fb');
        var line = el('div', 'rh-ai-fb__line');
        line.appendChild(el('span', 'rh-ai-fb__label', 'Was this right?'));
        function btn(icon, text) {
            var x = el('button', 'rh-ai-fb__btn');
            x.type = 'button';
            var i = document.createElement('i');
            i.className = 'fas ' + icon;
            i.setAttribute('aria-hidden', 'true');
            x.appendChild(i);
            x.appendChild(document.createTextNode(' ' + text));
            return x;
        }
        var up = btn('fa-thumbs-up', 'Helpful');
        var down = btn('fa-thumbs-down', 'Not right');
        line.appendChild(up);
        line.appendChild(down);
        row.appendChild(line);
        var box = el('div', 'rh-ai-fb__box');
        box.hidden = true;
        var tid = 'rhAiFix' + id;
        var lab = el('label', 'rh-ai-fb__boxlabel', 'What should it have said? (optional)');
        lab.setAttribute('for', tid);
        var ta = document.createElement('textarea');
        ta.id = tid;
        ta.rows = 2;
        ta.maxLength = 1000;
        var send = el('button', 'rh-ai-card__btn btn-primary rh-ai-fb__send', 'Send');
        send.type = 'button';
        box.appendChild(lab);
        box.appendChild(ta);
        box.appendChild(el('p', 'rh-ai-help', 'Facts and wording only, for example "breakfast starts at 6:30". It never changes what the Assistant may see or do.'));
        box.appendChild(send);
        row.appendChild(box);
        var note = el('p', 'rh-ai-fb__note');
        note.setAttribute('role', 'status');
        row.appendChild(note);
        function mark(r) {
            up.setAttribute('aria-pressed', r > 0 ? 'true' : 'false');
            down.setAttribute('aria-pressed', r < 0 ? 'true' : 'false');
        }
        function rate(r, correction) {
            up.disabled = down.disabled = send.disabled = true;
            post({ action: 'feedback', message_id: id, rating: r, correction: correction || '' }).then(function (j) {
                up.disabled = down.disabled = send.disabled = false;
                if (!j || !j.success) { note.textContent = (j && j.error) || 'That could not be saved.'; return; }
                mark(j.rating);
                note.textContent = j.note || 'Thanks.';
                if (correction) { box.hidden = true; ta.value = ''; }
            }).catch(function () {
                up.disabled = down.disabled = send.disabled = false;
                note.textContent = 'Could not reach the server.';
            });
        }
        mark(rated);
        up.addEventListener('click', function () { box.hidden = true; rate(1, ''); });
        down.addEventListener('click', function () { box.hidden = false; rate(-1, ''); setTimeout(function () { ta.focus(); }, 0); });
        send.addEventListener('click', function () { if (String(ta.value).trim()) { rate(-1, String(ta.value).trim()); } });
        return row;
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
        // Suggestions sit at the end of the conversation and scroll with it, so on a small screen they never
        // squeeze the messages or push the question box away.
        if (chipsBox.childNodes.length) { stream.appendChild(chipsBox); scrollDown(); }
    }
    function welcome() {
        stream.textContent = '';
        var w = document.createElement('div');
        w.className = 'rh-ai-welcome';
        var h = document.createElement('p');
        h.innerHTML = '<strong>Hello.</strong>';
        var t = document.createElement('p');
        t.textContent = panel.getAttribute('data-greeting') || 'Tap a suggestion below to start.'; // worded on the server for the business type
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
    // A screen in this admin folder; a booking's own screen also carries its id (booking-details.php?id=12: a booking note)
    function safePage(u) { return /^(api\/)?[a-z0-9\-]+\.php(\?id=\d+)?$/i.test(String(u || '')); }
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
                var inp;
                if (f.type === 'textarea') {
                    inp = document.createElement('textarea');
                    inp.rows = 6;
                    inp.maxLength = parseInt(f.max, 10) || 2000;
                } else {
                    inp = document.createElement('input');
                    inp.type = ['email', 'tel'].indexOf(f.type) >= 0 ? f.type : 'text';
                    inp.maxLength = parseInt(f.max, 10) || 200;
                }
                inp.id = id; inp.name = f.name;
                inp.value = f.value || ''; inp.className = 'rh-ai-card__input';
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
        var pw = null;
        if (live && c.confirm) { // risky card: the person's own password (checked on the server). Hidden until needed:
            // a choice on the card (e.g. "Email it to the guest too") can make the server ask for it at Confirm.
            var pid = 'rhAiP' + c.id;
            var pbox = el('div', 'rh-ai-card__pwbox');
            var plab = el('label', 'rh-ai-card__label', 'Your password');
            plab.setAttribute('for', pid);
            pw = document.createElement('input');
            pw.type = 'password'; pw.id = pid; pw.className = 'rh-ai-card__input rh-ai-card__pw'; pw.autocomplete = 'current-password';
            pbox.appendChild(plab); pbox.appendChild(pw);
            pbox.appendChild(el('p', 'rh-ai-card__help', 'This cannot be undone from the chat (it changes a booking or room, or sends to a guest), so it asks for your own account password.'));
            pbox.hidden = !c.password;
            box.appendChild(pbox);
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
            var check = function () { ok.disabled = inputs.some(function (i) { return i.required && !String(i.value).trim(); }) || (pw !== null && !pw.parentNode.hidden && !pw.value); };
            inputs.forEach(function (i) { i.addEventListener('input', check); });
            if (pw) { pw.addEventListener('input', check); }
            check();
            ok.addEventListener('click', function () { confirmCard(box, c, inputs, ok, no, st, pw); });
            no.addEventListener('click', function () {
                ok.disabled = true; no.disabled = true;
                post({ action: 'cancel', card_id: c.id }, undefined, actEndpoint).then(function (j) { if (j && j.card) { fillCard(box, j.card); } });
            });
            btns.appendChild(ok); btns.appendChild(no);
        }
        if (btns.childNodes.length) { box.appendChild(btns); }
    }
    function confirmCard(box, c, inputs, ok, no, st, pw) {
        var fields = {};
        inputs.forEach(function (i) { fields[i.name] = i.value; });
        ok.disabled = true; no.disabled = true;
        st.textContent = 'Checking the card again...';
        var body = { action: 'confirm', card_id: c.id, fields: JSON.stringify(fields) };
        if (pw && !pw.parentNode.hidden) { body.password = pw.value; pw.value = ''; }
        post(body, undefined, actEndpoint).then(function (j) {
            if (!j || !j.success) {
                if (j && j.card) { fillCard(box, j.card); }
                if (j && j.new_card) { box.parentNode.insertBefore(card(j.new_card), box.nextSibling); }
                var s2 = box.querySelector('.rh-ai-card__state');
                if (s2) { s2.textContent = (j && j.error) || 'That could not be confirmed.'; }
                if (pw && j && j.need_password) { pw.parentNode.hidden = false; } // the server decided this one needs the password
                var pwOn = !!pw && !pw.parentNode.hidden;
                if (!j || !j.card) { ok.disabled = pwOn; no.disabled = false; if (pwOn && !(j && j.locked)) { pw.focus(); } }
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
            var body;
            var hdr = { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': csrf() };
            if (s.json) { // the screen's own button sends JSON (Tentative Bookings: extend hold, send quotation)
                var o = { csrf: csrf(), csrf_token: csrf() };
                (s.fields || []).forEach(function (f) { o[String(f[0])] = String(f[1]); });
                body = JSON.stringify(o);
                hdr['Content-Type'] = 'application/json';
            } else {
                body = new FormData();
                body.append('csrf_token', csrf());
                (s.fields || []).forEach(function (f) { body.append(String(f[0]), String(f[1])); });
            }
            return fetch(s.url, { method: 'POST', body: body, credentials: 'same-origin', headers: hdr })
                .then(function (r) { return r.text(); })
                .then(function (t) {
                    var j = null;
                    try { j = JSON.parse(t); } catch (e) { j = null; }
                    if (s.guard === 'assess_clear') {
                        var clear = j && j.success && !(j.settlement && j.settlement.needed) && (parseFloat(j.balance_due || 0) <= tolerance);
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
        if (view === 'alerts') { loadAlerts(true); }
        if (view === 'chat') { focusLater(input); }
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
            var dg = document.getElementById('rhAiPrefDigest');
            if (dg) { dg.value = j.prefs.digest || 'on'; }
        });
    }
    function openConversation(id) {
        post({ action: 'load', conversation_id: id }).then(function (j) {
            if (!j || !j.success) { newChat(); return; }
            state.conv = j.conversation_id;
            store(KEY_CONV, state.conv);
            stream.textContent = '';
            if (!j.messages.length) { welcome(); }
            j.messages.forEach(function (m) { addBubble(m.role, m.text, { links: m.links, notice: m.notice, items: m.items, cards: m.cards, messageId: m.id, rated: m.rated }); });
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
            addBubble('assistant', j.reply, { items: j.items, messageId: j.message_id });
            setChips(j.chips || []);
        }).catch(function () { busy(false); });
    }
    // Clear (owner 2026-10-29): this chat or all of mine are archived on the server (never deleted), then a fresh chat
    function clearChats(scope) {
        var msg = document.getElementById('rhAiClearMsg');
        var btns = panel.querySelectorAll('[data-ai="clear-this"], [data-ai="clear-all"]');
        msg.hidden = true;
        if (scope === 'this' && !state.conv) { newChat(); return; }
        btns.forEach(function (x) { x.disabled = true; });
        post({ action: 'clear', scope: scope, conversation_id: state.conv || 0 }).then(function (j) {
            btns.forEach(function (x) { x.disabled = false; });
            if (!j || !j.success) { msg.textContent = (j && j.error) || 'The chats could not be cleared just now. Try again.'; msg.hidden = false; return; }
            newChat();
        }).catch(function () {
            btns.forEach(function (x) { x.disabled = false; });
            msg.textContent = 'Could not reach the server. Check the connection and try again.';
            msg.hidden = false;
        });
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
                addBubble('assistant', j.reply, { links: j.links, notice: j.notice, items: j.items, cards: j.cards, messageId: j.message_id });
                setChips(j.chips || []);
            })
            .catch(function (e) {
                busy(false);
                addError(e && e.name === 'AbortError' ? 'Stopped.' : 'Could not reach the Assistant. Check the connection and try again.');
            })
            .finally(function () { focusLater(input); });
    }
    function autosize() {
        input.style.height = 'auto';
        var edge = input.offsetHeight - input.clientHeight; // the border (the box is border-box)
        var cap = parseFloat(window.getComputedStyle(input).maxHeight) || 140; // lower on short screens (ai-panel.css)
        input.style.height = Math.min(input.scrollHeight + edge, cap) + 'px';
        input.style.overflowY = input.scrollHeight + edge > cap ? 'auto' : 'hidden';
    }

    // How much of an element at the bottom edge is on screen, in the drawer's own CSS px (the page may be scaled by
    // Display size = html zoom).
    function footerLift(f) {
        var z = parseFloat(window.getComputedStyle(document.documentElement).zoom) || 1;
        return Math.max(0, Math.round((window.innerHeight - f.getBoundingClientRect().top) / z));
    }
    // The "Install Admin App" bar (admin/js/pwa-install.js) while it shows; 0 when it is missing or hidden.
    function barLift() {
        var b = document.getElementById('admin-pwa-banner');
        if (!b || b.hidden) { return 0; }
        var cs = window.getComputedStyle(b);
        if (cs.display === 'none' || cs.visibility === 'hidden' || b.getBoundingClientRect().height <= 0) { return 0; }
        return footerLift(b);
    }
    // The drawer stops above the footer and above the install bar: the larger of the two on screen. The phone/tablet
    // sheet keeps its full height down to the install bar only (--rh-ai-bar, ai-panel.css).
    function bottomLift() {
        var f = document.getElementById('rhAdminFooter');
        return Math.max(f ? footerLift(f) : 0, barLift());
    }
    function fitFooter() {
        var lift = 0, bar = 0;
        if (!panel.hidden) {
            bar = barLift();
            lift = bottomLift();
        }
        panel.style.setProperty('--rh-ai-bottom', lift + 'px');
        panel.style.setProperty('--rh-ai-bar', bar + 'px');
    }
    // The footer and the bar also move without a window scroll (inner scroll areas, content loading, zoom, the
    // install bar coming and going), so re-measure every frame while the drawer is open; it only writes when a value changed.
    var fitRaf = 0;
    function fitLoop() {
        fitRaf = 0;
        if (panel.hidden) { return; }
        if (panel.style.getPropertyValue('--rh-ai-bottom') !== bottomLift() + 'px' ||
            panel.style.getPropertyValue('--rh-ai-bar') !== barLift() + 'px') { fitFooter(); }
        fitRaf = window.requestAnimationFrame(fitLoop);
    }
    function startFit() { if (!fitRaf) { fitRaf = window.requestAnimationFrame(fitLoop); } }
    window.addEventListener('scroll', fitFooter, { passive: true, capture: true });
    window.addEventListener('resize', fitFooter);

    // ── Side by side on big screens (owner 2026-10-30) ───────────────────────
    // When the drawer is open and the page still keeps a usable width beside it, html gets rh-ai-docked and the
    // page (#rh-admin-page) gives up room on its right (ai-panel.css), so nothing sits under the drawer. The page
    // and the drawer slide together; once they settle, one resize event lets charts and tables re-fit.
    var root = document.documentElement;
    // CSS px the page must keep beside the sidebar and the drawer, or the drawer overlays as before. 720 is about the
    // narrowest page the laptop layout already handles (a 1025px screen less the 292px sidebar); narrower pages keep
    // laptop-sized rows that would poke out of their cards.
    var DOCK_MIN_PAGE = 720;
    var MAX_W = 720; // the widest drawer (CSS px), and never more than half the screen
    var reduceMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : { matches: false };
    function pageZoom() { return parseFloat(window.getComputedStyle(root).zoom) || 1; }
    function slides() { return window.innerWidth >= 1025 && !reduceMotion.matches; } // the phone/tablet sheet does not slide
    function viewW() { return window.innerWidth / pageZoom(); }
    function sideW() {
        var nav = document.querySelector('.admin-nav');
        return nav ? Math.max(0, nav.getBoundingClientRect().right) / pageZoom() : 0;
    }
    // The usual drawer width (420px, 380px under 1440) from the stylesheet; a dragged width never changes it.
    function baseW() { return parseFloat(window.getComputedStyle(root).getPropertyValue('--rh-ai-w-base')) || 420; }
    // Decided at the usual width, so widening the drawer never flips the page from side by side to covered.
    function dockable() {
        if (window.innerWidth < 1025) { return false; }
        return viewW() - sideW() - baseW() >= DOCK_MIN_PAGE;
    }

    // ── Drawer width (drag its left edge, like the sidebar's right edge) ─────
    // The chosen width is kept per person in this browser. It is used whenever it fits: at most MAX_W and half the
    // screen, and when side by side the page still keeps DOCK_MIN_PAGE; otherwise the widest width that does fit.
    var navEl = document.querySelector('.admin-nav');
    var widthKey = 'rhAiPanelWidth:v1:' + ((navEl && navEl.getAttribute('data-admin-user-id')) || 'guest');
    var wanted = 0; // 0 = the usual width
    try { wanted = parseInt(localStorage.getItem(widthKey) || '0', 10) || 0; } catch (e) { wanted = 0; }
    function widthRange() {
        var min = baseW();
        var max = Math.min(MAX_W, Math.floor(viewW() / 2));
        if (dockable()) { max = Math.min(max, Math.floor(viewW() - sideW() - DOCK_MIN_PAGE)); }
        return { min: min, max: Math.max(min, max) };
    }
    function clampW(w, r) { return Math.max(r.min, Math.min(r.max, Math.round(w))); }
    var grip = document.createElement('div');
    grip.className = 'rh-ai-resize';
    grip.id = 'rhAiResize';
    grip.tabIndex = 0;
    grip.setAttribute('role', 'separator');
    grip.setAttribute('aria-orientation', 'vertical');
    grip.setAttribute('aria-label', 'Resize the Assistant');
    grip.setAttribute('aria-controls', 'rhAiPanel');
    grip.title = 'Drag to make the Assistant wider or narrower. Double-click to go back to the usual width.';
    panel.parentNode.insertBefore(grip, panel.nextSibling);
    var shownW = 0; // the width on screen now
    var styledW = -1; // what is written on html (0 = nothing, the stylesheet's usual width)
    function fitWidth() {
        var r = widthRange();
        var w = window.innerWidth < 1025 ? r.min : (wanted > 0 ? clampW(wanted, r) : r.min);
        var css = w === r.min ? 0 : w;
        if (css !== styledW) {
            styledW = css;
            if (css > 0) { root.style.setProperty('--rh-ai-w', css + 'px'); } else { root.style.removeProperty('--rh-ai-w'); }
        }
        if (w !== shownW) {
            shownW = w;
            grip.setAttribute('aria-valuenow', String(w));
            grip.setAttribute('aria-valuetext', w + ' pixels wide');
        }
        grip.setAttribute('aria-valuemin', String(r.min));
        grip.setAttribute('aria-valuemax', String(r.max));
        return w;
    }
    function keepWidth() {
        try {
            if (wanted > 0) { localStorage.setItem(widthKey, String(wanted)); } else { localStorage.removeItem(widthKey); }
        } catch (e) { /* private mode */ }
    }
    function chooseWidth(w) { // from a drag, a key or a reset; 0 = the usual width
        var r = widthRange();
        wanted = w > 0 ? clampW(w, r) : 0;
        if (wanted <= r.min) { wanted = 0; }
        return fitWidth();
    }
    fitWidth();
    var drag = null;
    function onDragMove(e) {
        if (!drag) { return; }
        chooseWidth(drag.w + (drag.x - e.clientX) / pageZoom()); // only the width moves while dragging
    }
    function endDrag() {
        if (!drag) { return; }
        var from = drag.w;
        try { grip.releasePointerCapture(drag.id); } catch (e) { /* already released */ }
        drag = null;
        root.classList.remove('rh-ai-resizing');
        document.removeEventListener('pointermove', onDragMove);
        document.removeEventListener('pointerup', endDrag);
        document.removeEventListener('pointercancel', endDrag);
        keepWidth();
        if (shownW !== from && root.classList.contains('rh-ai-docked')) { // one resize so charts and tables re-fit
            clearTimeout(refitTimer);
            window.dispatchEvent(new Event('resize'));
        }
    }
    grip.addEventListener('pointerdown', function (e) {
        if (e.button !== 0 || !state.open || window.innerWidth < 1025) { return; }
        e.preventDefault();
        drag = { x: e.clientX, w: shownW || fitWidth(), id: e.pointerId };
        root.classList.add('rh-ai-resizing'); // transitions off: the page edge follows the pointer
        try { grip.setPointerCapture(e.pointerId); } catch (err) { /* not a real pointer */ }
        document.addEventListener('pointermove', onDragMove);
        document.addEventListener('pointerup', endDrag);
        document.addEventListener('pointercancel', endDrag);
    });
    grip.addEventListener('lostpointercapture', endDrag);
    grip.addEventListener('dblclick', function () { chooseWidth(0); keepWidth(); });
    grip.addEventListener('keydown', function (e) {
        var step = e.shiftKey ? 24 : 8;
        var cur = shownW || fitWidth();
        if (e.key === 'ArrowLeft') { chooseWidth(cur + step); } // the edge is on the left: left is wider
        else if (e.key === 'ArrowRight') { chooseWidth(cur - step); }
        else if (e.key === 'Home') { chooseWidth(0); }
        else { return; }
        e.preventDefault();
        keepWidth();
    });
    var refitTimer = 0;
    function refitPage() { // one resize event after the page has settled (never during the slide)
        clearTimeout(refitTimer);
        refitTimer = setTimeout(function () { window.dispatchEvent(new Event('resize')); }, slides() ? 320 : 0);
    }
    document.addEventListener('transitionend', function (e) {
        if (e.target && e.target.id === 'rh-admin-page' && e.propertyName === 'padding-right') {
            clearTimeout(refitTimer);
            window.dispatchEvent(new Event('resize'));
        }
    });
    function syncDock() { // idempotent: safe to call from the resize event it fires itself
        fitWidth(); // the window or the sidebar changed: the widest width that still fits
        var want = state.open && dockable();
        if (want !== root.classList.contains('rh-ai-docked')) {
            root.classList.toggle('rh-ai-docked', want);
            refitPage();
        }
    }
    window.addEventListener('resize', syncDock);
    // The sidebar collapses or is dragged wider. In-app navigation can swap the sidebar element, so the
    // current one is watched again after every page change.
    var navRo = window.ResizeObserver ? new ResizeObserver(function () { syncDock(); }) : null;
    var navWatched = null;
    function watchNav() {
        var n = document.querySelector('.admin-nav');
        if (!navRo || !n || n === navWatched) { return; }
        if (navWatched) { navRo.unobserve(navWatched); }
        navRo.observe(n);
        navWatched = n;
    }
    watchNav();
    document.addEventListener('rh:content-updated', function () { watchNav(); syncDock(); });

    // Keep the closed launcher clear of the bulk-action bar, the install bar and the help button at the bottom right.
    var liftRaf = 0;
    var LAUNCH_BOTTOM = 64; // admin/css/ai-panel.css .rh-ai-launch bottom
    function placeLauncher() {
        liftRaf = 0;
        var z = pageZoom();
        var vh = window.innerHeight / z; // everything below in the page's own CSS px
        var b = LAUNCH_BOTTOM;
        var lh = launch.offsetHeight || 44;
        function top(el) { var r = el.getBoundingClientRect(); return r.height > 0 ? r.top / z : null; }
        ['.admin-bulk-bar:not([hidden])', '#admin-pwa-banner:not([hidden])'].forEach(function (sel) {
            var el = document.querySelector(sel);
            var t = el ? top(el) : null;
            if (t !== null && t < vh) { b = Math.max(b, Math.round(vh - t + 10)); }
        });
        var fab = document.querySelector('.rh-help-toggle:not([data-inline="1"])');
        if (fab && window.getComputedStyle(fab).position === 'fixed' && window.getComputedStyle(fab).display !== 'none') {
            var fr = fab.getBoundingClientRect();
            var ft = fr.top / z, fb = fr.bottom / z;
            if (fr.height > 0 && ft < vh - b && fb > vh - b - lh) { b = Math.round(vh - ft + 8); } // sit just above it
        }
        var lift = b === LAUNCH_BOTTOM ? '' : b + 'px';
        if (launch.style.bottom !== lift) { launch.style.bottom = lift; }
    }
    function liftLater() { if (!liftRaf) { liftRaf = window.requestAnimationFrame(placeLauncher); } }
    ['change', 'click', 'pointerup'].forEach(function (t) { document.addEventListener(t, liftLater, { passive: true, capture: true }); });
    window.addEventListener('resize', liftLater);
    document.addEventListener('rh:content-updated', liftLater);
    if (window.MutationObserver) { // install bar on/off, sidebar collapsed/expanded
        new MutationObserver(function () { liftLater(); syncDock(); }).observe(document.body, { attributes: true, attributeFilter: ['class'] });
    }
    liftLater();
    setTimeout(liftLater, 1500);

    // ── Open / close ─────────────────────────────────────────────────────────
    var hideTimer = 0;
    function hidePanelNow() { clearTimeout(hideTimer); hideTimer = 0; if (!state.open) { panel.hidden = true; } }
    panel.addEventListener('transitionend', function (e) {
        if (e.target === panel && e.propertyName === 'transform' && !state.open) { hidePanelNow(); }
    });
    function open() {
        clearTimeout(hideTimer); hideTimer = 0;
        state.open = true;
        panel.hidden = false;
        void panel.offsetWidth; // start the slide from the closed position
        root.classList.add('rh-ai-in');
        syncDock();
        fitFooter();
        startFit();
        launch.setAttribute('aria-expanded', 'true');
        root.classList.add('rh-ai-is-open');
        store(KEY_OPEN, '1');
        if (panel.getAttribute('data-locked') === '1') { lockPanel(); return; }
        if (!state.loaded) {
            state.loaded = true;
            var saved = parseInt(read(KEY_CONV) || '0', 10);
            if (saved > 0) { openConversation(saved); } else { welcome(); loadSuggestions(); }
            autoBriefing();
        }
        show('chat');
    }
    function close() {
        if (!state.open) { return; }
        state.open = false;
        endDrag();
        moreMenu(false);
        root.classList.remove('rh-ai-in', 'rh-ai-is-open');
        syncDock();
        launch.setAttribute('aria-expanded', 'false');
        store(KEY_OPEN, null);
        launch.focus();
        if (slides()) { hideTimer = setTimeout(hidePanelNow, 320); } else { hidePanelNow(); } // after the slide out
    }

    // "More": your chats, clear chats, reply settings (one small menu instead of three more icons)
    var moreBtn = document.getElementById('rhAiMoreBtn');
    var moreBox = document.getElementById('rhAiMoreMenu');
    function moreMenu(on) {
        if (!moreBox) { return; }
        moreBox.hidden = !on;
        moreBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
        if (on) { var first = moreBox.querySelector('button'); if (first) { first.focus(); } }
    }
    document.addEventListener('click', function (e) {
        if (moreBox && !moreBox.hidden && !e.target.closest('.rh-ai-more')) { moreMenu(false); }
    });

    launch.addEventListener('click', function () { if (!state.open) { open(); } else { close(); } });
    panel.addEventListener('click', function (e) {
        var b = e.target.closest('[data-ai]');
        if (!b) { return; }
        var what = b.getAttribute('data-ai');
        if (what === 'more') { moreMenu(moreBox.hidden); return; }
        moreMenu(false);
        if (what === 'close') { close(); }
        else if (what === 'new') { newChat(); }
        else if (what === 'clear') { document.getElementById('rhAiClearMsg').hidden = true; show('clear'); }
        else if (what === 'clear-this') { clearChats('this'); }
        else if (what === 'clear-all') { clearChats('all'); }
        else if (what === 'chats') { show('chats'); }
        else if (what === 'alerts') { show('alerts'); }
        else if (what === 'prefs') { show('prefs'); }
        else if (what === 'back') { show('chat'); }
        else if (what === 'stop' && state.ctrl) { state.ctrl.abort(); }
        else if (what === 'prefs-reset') {
            b.disabled = true;
            post({ action: 'prefs_reset' }).then(function () { b.disabled = false; loadPrefs(); show('chat'); addBubble('assistant', 'Your reply settings are back to normal.', {}); })
                .catch(function () { b.disabled = false; });
        }
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(input.value); }
    });
    input.addEventListener('input', autosize);
    document.getElementById('rhAiPrefs').addEventListener('submit', function (e) {
        e.preventDefault();
        var val = function (id) { return document.getElementById(id).value; }; // (form.length is the control count, not the field)
        var pdata = { action: 'prefs_set', length: val('rhAiPrefLength'), tone: val('rhAiPrefTone'), language: val('rhAiPrefLang'), briefing: val('rhAiPrefBrief') };
        if (document.getElementById('rhAiPrefDigest')) { pdata.digest = val('rhAiPrefDigest'); }
        post(pdata).then(function () { show('chat'); });
    });
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !state.open) { return; }
        if (moreBox && !moreBox.hidden) { moreMenu(false); moreBtn.focus(); return; } // the menu first, then the panel
        close();
    });
    // In-app navigation: the panel stays; starter chips follow the new screen when the chat is empty.
    document.addEventListener('rh:content-updated', function () {
        if (!panel.hidden && !state.conv && !state.busy) { loadSuggestions(); }
        applyPrefill();
    });
    applyPrefill();

    // ── Idle lock (Phase 4c): the chat is wiped from the screen until the person's own password is typed ──
    function lockPanel() {
        panel.setAttribute('data-locked', '1');
        if (state.ctrl) { try { state.ctrl.abort(); } catch (e) { /* already done */ } }
        busy(false);
        stream.textContent = '';
        chipsBox.textContent = '';
        var cl = document.getElementById('rhAiChatList');
        if (cl) { cl.textContent = ''; }
        state.loaded = false;
        show('lock');
        var p = document.getElementById('rhAiUnlockPw');
        if (p && !panel.hidden) { focusLater(p); }
    }
    var unlockForm = document.getElementById('rhAiUnlock');
    if (unlockForm) {
        unlockForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var p = document.getElementById('rhAiUnlockPw');
            var msg = document.getElementById('rhAiUnlockMsg');
            var btn = unlockForm.querySelector('button');
            btn.disabled = true;
            post({ action: 'unlock', password: p.value }).then(function (j) {
                btn.disabled = false;
                p.value = '';
                if (!j || !j.success) { msg.textContent = (j && j.error) || 'That did not work.'; msg.hidden = false; p.focus(); return; }
                msg.hidden = true;
                panel.setAttribute('data-locked', '0');
                open();
            }).catch(function () { btn.disabled = false; });
        });
    }

    // ── Alerts (Phase 4c): a plain count on the button, the list in the panel ──
    var alertsOn = panel.getAttribute('data-alerts') === '1';
    function setAlertCount(n) {
        [document.getElementById('rhAiLaunchCount'), document.getElementById('rhAiAlertCount')].forEach(function (b) {
            if (!b) { return; }
            b.textContent = n > 0 ? String(n) : '';
            b.hidden = !(n > 0);
        });
        launch.setAttribute('aria-label', n > 0 ? 'Assistant, ' + n + ' alert' + (n === 1 ? '' : 's') : 'Assistant');
    }
    function renderAlerts(list) {
        var ul = document.getElementById('rhAiAlertList');
        if (!ul) { return; }
        ul.textContent = '';
        if (!list.length) { ul.appendChild(el('li', 'rh-ai-alert rh-ai-alert--none', 'Nothing needs you right now.')); }
        list.forEach(function (a) {
            var li = el('li', 'rh-ai-alert rh-ai-alert--' + (a.kind || 'time'));
            li.appendChild(el('p', 'rh-ai-alert__title', a.title));
            if (a.detail) { li.appendChild(el('p', 'rh-ai-alert__detail', a.detail)); }
            var row = el('div', 'rh-ai-alert__btns');
            if (a.link && safeUrl(a.link.url) && !safeUrl(a.link.url).guide) {
                var go = el('a', 'btn btn-secondary btn-sm', a.link.label || 'Open');
                go.href = a.link.url;
                row.appendChild(go);
            }
            var dis = el('button', 'btn btn-secondary btn-sm', 'Dismiss');
            dis.type = 'button';
            dis.addEventListener('click', function () {
                dis.disabled = true;
                post({ action: 'alert_dismiss', alert_id: a.id }).then(function () {
                    li.remove();
                    setAlertCount(document.querySelectorAll('#rhAiAlertList .rh-ai-alert:not(.rh-ai-alert--none)').length);
                });
            });
            row.appendChild(dis);
            li.appendChild(row);
            ul.appendChild(li);
        });
    }
    function loadAlerts(render) {
        if (!alertsOn || panel.getAttribute('data-locked') === '1') { return; }
        post({ action: 'alerts' }).then(function (j) {
            if (!j || !j.success) { return; }
            setAlertCount(j.alerts.length);
            if (render) { renderAlerts(j.alerts); }
        }).catch(function () { /* alerts are optional */ });
    }
    if (alertsOn) {
        setTimeout(function () { loadAlerts(false); }, 4000);
        setInterval(function () {
            if (!document.hidden) { loadAlerts(!panel.hidden && !panel.querySelector('[data-view="alerts"]').hidden); }
        }, 600000);
    }

    // When the phone keyboard opens, keep the panel inside the part of the screen
    // that is still visible, and keep the latest message in view.
    var vv = window.visualViewport;
    if (vv) {
        var fitToKeyboard = function () {
            var atEnd = stream.scrollHeight - stream.scrollTop - stream.clientHeight < 80;
            if (!panel.hidden && window.innerWidth <= 1024 && vv.height < window.innerHeight - 1) {
                panel.style.top = vv.offsetTop + 'px';
                panel.style.bottom = 'auto';
                panel.style.height = vv.height + 'px';
            } else {
                panel.style.top = '';
                panel.style.bottom = '';
                panel.style.height = '';
            }
            if (atEnd && !panel.hidden) { scrollDown(); }
        };
        vv.addEventListener('resize', fitToKeyboard);
        vv.addEventListener('scroll', fitToKeyboard);
        input.addEventListener('focus', function () { setTimeout(fitToKeyboard, 300); });
    }

    if (read(KEY_OPEN) === '1') { // it was open on the last screen: show it in place, no slide
        root.classList.add('rh-ai-noanim');
        open();
        window.requestAnimationFrame(function () { window.requestAnimationFrame(function () { root.classList.remove('rh-ai-noanim'); }); });
    }
})();
