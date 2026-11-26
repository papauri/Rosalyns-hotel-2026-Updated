/*
 * Edit an email's wording before it goes (owner 2026-11-26). window.RhEmailWording.open({key, bookingId,
 * keyInput, htmlInput, onDone}) loads the default body from email-wording-data.php into an editor. Fields from
 * the booking show as grey chips; money and payment chips are locked. "Use this wording" writes the edited body
 * (chips back to {{field}}) into the two hidden inputs; the server checks it again before anything is sent.
 */
(function () {
    'use strict';
    if (window.RhEmailWording) return;

    var state = null; // {opts, data, overlay, editor, msg, lockedSeq}

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function tokenInfo(name) {
        var t = state && state.data && state.data.tokens ? state.data.tokens[name] : null;
        return t || { label: name.replace(/_/g, ' '), value: '', financial: false };
    }

    function makeChip(name) {
        var info = tokenInfo(name);
        var chip = document.createElement('span');
        chip.className = 'ew-chip' + (info.financial ? ' ew-chip--locked' : '');
        chip.setAttribute('contenteditable', 'false');
        chip.setAttribute('data-token', name);
        chip.title = info.financial
            ? 'Locked: filled from the booking when the email is sent'
            : 'Filled from the booking when the email is sent. You may delete it.';
        if (info.financial) {
            var lock = document.createElement('i');
            lock.className = 'fas fa-lock';
            lock.setAttribute('aria-hidden', 'true');
            chip.appendChild(lock);
            chip.appendChild(document.createTextNode(' '));
        }
        chip.appendChild(document.createTextNode(info.label + (info.value ? ': ' + info.value : '')));
        return chip;
    }

    // Text {{field}} -> chips; rows that hold a field between table rows become read-only.
    function chipify(root) {
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var texts = [];
        while (walker.nextNode()) {
            if (/\{\{[a-z0-9_]+\}\}/i.test(walker.currentNode.nodeValue)) texts.push(walker.currentNode);
        }
        texts.forEach(function (node) {
            var parts = node.nodeValue.split(/(\{\{[a-z0-9_]+\}\})/i);
            var frag = document.createDocumentFragment();
            parts.forEach(function (p) {
                var m = /^\{\{([a-z0-9_]+)\}\}$/i.exec(p);
                if (m) frag.appendChild(makeChip(m[1]));
                else if (p !== '') frag.appendChild(document.createTextNode(p));
            });
            node.parentNode.replaceChild(frag, node);
        });
        root.querySelectorAll('tr[data-ew-block]').forEach(function (tr) {
            tr.setAttribute('contenteditable', 'false');
            tr.classList.add('ew-block');
        });
    }

    // Editor -> HTML with {{field}} back in place of the chips (and the table-row fields back as bare fields).
    function serialise() {
        var clone = state.editor.cloneNode(true);
        clone.querySelectorAll('.ew-chip').forEach(function (chip) {
            chip.parentNode.replaceChild(document.createTextNode('{{' + chip.getAttribute('data-token') + '}}'), chip);
        });
        var blocks = [];
        clone.querySelectorAll('tr[data-ew-block]').forEach(function (tr, i) {
            blocks.push(tr.textContent.trim());
            tr.parentNode.replaceChild(document.createTextNode('[[EWBLOCK' + i + ']]'), tr);
        });
        return clone.innerHTML.replace(/\[\[EWBLOCK(\d+)\]\]/g, function (_, i) { return blocks[+i] || ''; });
    }

    function lockedSequence(root) {
        var seq = [];
        root.querySelectorAll('.ew-chip--locked').forEach(function (c) { seq.push(c.getAttribute('data-token')); });
        return seq.join(',');
    }

    function showMsg(text, isError) {
        if (!state || !state.msg) return;
        state.msg.textContent = text || '';
        state.msg.hidden = !text;
        state.msg.classList.toggle('ew-msg--error', !!isError);
    }

    function rangeHitsLocked(range, backward) {
        var locked = state.editor.querySelectorAll('.ew-chip--locked, tr.ew-block');
        if (!range.collapsed) {
            for (var i = 0; i < locked.length; i++) {
                if (range.intersectsNode(locked[i])) return true;
            }
            return false;
        }
        var c = range.startContainer, o = range.startOffset, near = null;
        if (c.nodeType === 3) {
            if (backward && o > 0) return false;
            if (!backward && o < c.nodeValue.length) return false;
            near = backward ? c.previousSibling : c.nextSibling;
            if (!near && c.parentNode !== state.editor) near = backward ? c.parentNode.previousSibling : c.parentNode.nextSibling;
        } else {
            near = backward ? c.childNodes[o - 1] : c.childNodes[o];
        }
        if (!near || near.nodeType !== 1) return false;
        return near.matches('.ew-chip--locked, tr.ew-block') || !!near.querySelector('.ew-chip--locked, tr.ew-block');
    }

    function onBeforeInput(e) {
        var t = e.inputType || '';
        if (t.indexOf('delete') !== 0 && t !== 'insertFromDrop' && t !== 'insertReplacementText' && t.indexOf('insert') !== 0) return;
        var ranges = typeof e.getTargetRanges === 'function' ? e.getTargetRanges() : [];
        var range = null;
        if (ranges && ranges.length) {
            range = document.createRange();
            range.setStart(ranges[0].startContainer, ranges[0].startOffset);
            range.setEnd(ranges[0].endContainer, ranges[0].endOffset);
        } else {
            var sel = window.getSelection();
            if (sel && sel.rangeCount) range = sel.getRangeAt(0);
        }
        if (!range) return;
        var backward = /Backward/.test(t);
        if (t.indexOf('insert') === 0 && range.collapsed) return; // typing next to a chip is fine
        if (rangeHitsLocked(range, backward)) {
            e.preventDefault();
            showMsg('Prices, totals and payment details are locked and stay in the email.', true);
        }
    }

    function onPaste(e) {
        e.preventDefault();
        var text = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
        var sel = window.getSelection();
        if (sel && sel.rangeCount && !sel.getRangeAt(0).collapsed && rangeHitsLocked(sel.getRangeAt(0), false)) {
            showMsg('Prices, totals and payment details are locked and stay in the email.', true);
            return;
        }
        document.execCommand('insertText', false, text);
    }

    function onKey(e) {
        if (!state) return;
        if (e.key === 'Escape') {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            close(false);
        }
    }

    function load() {
        var d = state.data;
        state.editor.innerHTML = d.html || '';
        chipify(state.editor);
        state.lockedSeq = lockedSequence(state.editor);
        showMsg('');
    }

    function close(apply) {
        if (!state) return;
        var s = state;
        if (apply) {
            if (lockedSequence(s.editor) !== s.lockedSeq) {
                showMsg('A locked field was removed or moved out of order. Use "Reset to default" and try again.', true);
                return;
            }
            var html = serialise();
            if (s.opts.keyInput) s.opts.keyInput.value = s.data.key;
            if (s.opts.htmlInput) s.opts.htmlInput.value = html;
        }
        window.removeEventListener('keydown', onKey, true);
        s.overlay.remove();
        var stillOpen = document.querySelector('.modal-overlay.active, .modal.active');
        if (!stillOpen) document.body.classList.remove('modal-open');
        if (s.opener && typeof s.opener.focus === 'function') s.opener.focus();
        state = null;
        if (apply && typeof s.opts.onDone === 'function') s.opts.onDone(true);
    }

    function build() {
        var o = document.createElement('div');
        o.className = 'ew-overlay';
        o.setAttribute('role', 'dialog');
        o.setAttribute('aria-modal', 'true');
        o.setAttribute('aria-labelledby', 'ewTitle');
        o.innerHTML =
            '<div class="ew-card">' +
            '<div class="ew-head"><h3 id="ewTitle">Edit email wording</h3>' +
            '<i class="help" data-tip="Changes the words of this one email only; the saved template stays the default. Grey fields are filled from the booking when it is sent. Fields with a lock (prices, totals, payment details) cannot be removed or typed.">?</i>' +
            '<button type="button" class="ew-x" data-ew-cancel aria-label="Close"><i class="fas fa-times" aria-hidden="true"></i></button></div>' +
            '<p class="ew-note"><i class="fas fa-lock" aria-hidden="true"></i> Prices, totals and payment details come from the booking and cannot be changed here.</p>' +
            '<p class="ew-msg" role="alert" hidden></p>' +
            '<div class="ew-editor-wrap"><div class="ew-editor" contenteditable="true" spellcheck="true" aria-label="Email wording"><p class="ew-loading">Loading...</p></div></div>' +
            '<div class="ew-actions">' +
            '<button type="button" class="btn btn-secondary" data-ew-reset>Reset to default</button>' +
            '<span class="ew-spacer"></span>' +
            '<button type="button" class="btn btn-secondary" data-ew-cancel>Cancel</button>' +
            '<button type="button" class="btn btn-primary" data-ew-done>Use this wording</button>' +
            '</div></div>';
        document.body.appendChild(o);
        o.addEventListener('click', function (e) {
            if (e.target === o || e.target.closest('[data-ew-cancel]')) close(false);
            else if (e.target.closest('[data-ew-reset]') && state && state.data) load();
            else if (e.target.closest('[data-ew-done]') && state && state.data) close(true);
        });
        return o;
    }

    function open(opts) {
        opts = opts || {};
        if (state) close(false);
        var overlay = build();
        state = {
            opts: opts,
            data: null,
            overlay: overlay,
            editor: overlay.querySelector('.ew-editor'),
            msg: overlay.querySelector('.ew-msg'),
            lockedSeq: '',
            opener: document.activeElement
        };
        state.editor.setAttribute('contenteditable', 'false');
        state.editor.addEventListener('beforeinput', onBeforeInput);
        state.editor.addEventListener('paste', onPaste);
        state.editor.addEventListener('drop', function (e) { e.preventDefault(); });
        window.addEventListener('keydown', onKey, true);
        document.body.classList.add('modal-open');
        var mine = state;
        var url = 'email-wording-data.php?key=' + encodeURIComponent(opts.key || '') + '&booking_id=' + encodeURIComponent(opts.bookingId || '');
        fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (state !== mine) return;
                if (!d || !d.ok) { showMsg((d && d.error) || 'Could not load the email wording.', true); state.editor.innerHTML = ''; return; }
                if (!d.editable) { showMsg(d.reason || 'This email uses fixed wording.', true); state.editor.innerHTML = ''; return; }
                state.data = d;
                state.editor.setAttribute('contenteditable', 'true');
                load();
                state.editor.focus();
            })
            .catch(function () {
                if (state === mine) { showMsg('Could not load the email wording.', true); state.editor.innerHTML = ''; }
            });
    }

    // The form's hidden inputs, created when missing (named as the server reads them).
    function inputsFor(form) {
        function get(name) {
            var el = form.querySelector('input[type="hidden"][name="' + name + '"]');
            if (!el) {
                el = document.createElement('input');
                el.type = 'hidden';
                el.name = name;
                form.appendChild(el);
            }
            return el;
        }
        return { keyInput: get('email_wording_key'), htmlInput: get('email_wording_html') };
    }

    function clearInputs(form) {
        if (!form) return;
        form.querySelectorAll('input[name="email_wording_key"], input[name="email_wording_html"]').forEach(function (el) { el.value = ''; });
    }

    window.RhEmailWording = { open: open, close: function () { close(false); }, inputsFor: inputsFor, clear: clearInputs };
})();
