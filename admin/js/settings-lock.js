/* High-value settings lock (owner 2026-11-25): VAT, bank details, tourism levy and the restaurant service charge stay
   greyed out (a disabled <fieldset data-lock-group="...">) until the signed-in person types their own password.
   The server (admin/api/settings-unlock.php + includes/settings-lock.php) checks the password and refuses any save
   without the unlock; this file only opens the fields on screen.

   Markup:  <button data-settings-unlock="bank" data-settings-unlock-name="bank account details" aria-controls="fs-id">
            <fieldset data-lock-group="bank" disabled> ... </fieldset>
            <p data-lock-state> status line (inside [data-lock-bar="bank"] or data-lock-state="bank")
   API:     RhSettingsLock.unlock(group, name, opener) -> Promise<boolean>
   Events:  document 'rh:settings-unlocked' / 'rh:settings-locked', detail { group } */
(function () {
    'use strict';
    if (window.RhSettingsLock) { return; }

    var TTL_MS = 10 * 60 * 1000;
    var timers = {};
    var dlg = null;
    var els = {};
    var pending = null; // { group, resolve, opener }

    function csrf() {
        if (window._rhCsrf) { return String(window._rhCsrf); }
        var inp = document.querySelector('input[name="csrf_token"]');
        return inp ? inp.value : '';
    }

    function fire(name, group) {
        var ev;
        try {
            ev = new CustomEvent(name, { detail: { group: group } });
        } catch (e) {
            ev = document.createEvent('CustomEvent');
            ev.initCustomEvent(name, false, false, { group: group });
        }
        document.dispatchEvent(ev);
    }

    function stateLines(group) {
        var out = [];
        Array.prototype.forEach.call(document.querySelectorAll('[data-lock-bar="' + group + '"] [data-lock-state], [data-lock-state="' + group + '"]'), function (el) {
            out.push(el);
        });
        return out;
    }

    function setState(group, open, text) {
        Array.prototype.forEach.call(document.querySelectorAll('fieldset[data-lock-group="' + group + '"]'), function (fs) {
            fs.disabled = !open;
            fs.classList.toggle('is-unlocked', open);
        });
        Array.prototype.forEach.call(document.querySelectorAll('[data-settings-unlock="' + group + '"]'), function (b) {
            b.hidden = open;
        });
        stateLines(group).forEach(function (p) {
            p.textContent = '';
            var i = document.createElement('i');
            i.className = 'fas ' + (open ? 'fa-lock-open' : 'fa-lock');
            i.setAttribute('aria-hidden', 'true');
            p.appendChild(i);
            p.appendChild(document.createTextNode(' ' + text));
        });
    }

    function opened(group) {
        setState(group, true, 'Unlocked for 10 minutes. Save to lock again.');
        if (timers[group]) { clearTimeout(timers[group]); }
        timers[group] = setTimeout(function () {
            setState(group, false, 'Locked again after 10 minutes. Unlock again to make a change.');
            fire('rh:settings-locked', group);
        }, TTL_MS);
        fire('rh:settings-unlocked', group);
    }

    function build() {
        if (dlg) { return; }
        dlg = document.createElement('dialog');
        dlg.className = 'rh-lockdlg';
        dlg.setAttribute('aria-labelledby', 'rh-lockdlg-title');
        dlg.innerHTML =
            '<form class="rh-lockdlg__form" method="dialog" novalidate>' +
            '<h2 class="rh-lockdlg__title" id="rh-lockdlg-title"><i class="fas fa-lock" aria-hidden="true"></i> <span></span></h2>' +
            '<p class="rh-lockdlg__text"></p>' +
            '<label class="rh-lockdlg__label" for="rh-lockdlg-pw">Your password</label>' +
            '<input class="rh-lockdlg__input" type="password" id="rh-lockdlg-pw" name="rh_lock_pw" autocomplete="current-password" required>' +
            '<p class="rh-lockdlg__error" role="alert" hidden></p>' +
            '<div class="rh-lockdlg__actions">' +
            '<button type="button" class="btn btn-outline rh-lockdlg__cancel"><i class="fas fa-xmark" aria-hidden="true"></i> Cancel</button>' +
            '<button type="submit" class="btn btn-primary rh-lockdlg__ok"><i class="fas fa-lock-open" aria-hidden="true"></i> Unlock</button>' +
            '</div></form>';
        document.body.appendChild(dlg);
        els.title = dlg.querySelector('.rh-lockdlg__title span');
        els.text = dlg.querySelector('.rh-lockdlg__text');
        els.pw = dlg.querySelector('.rh-lockdlg__input');
        els.err = dlg.querySelector('.rh-lockdlg__error');
        els.ok = dlg.querySelector('.rh-lockdlg__ok');
        els.cancel = dlg.querySelector('.rh-lockdlg__cancel');
        els.form = dlg.querySelector('form');

        els.cancel.addEventListener('click', function () { finish(false); });
        dlg.addEventListener('cancel', function (e) { e.preventDefault(); finish(false); }); // Esc
        els.form.addEventListener('submit', function (e) {
            e.preventDefault();
            submit();
        });
    }

    function showError(msg) {
        els.err.textContent = msg;
        els.err.hidden = false;
        els.pw.value = '';
        els.pw.setAttribute('aria-invalid', 'true');
        els.pw.focus();
    }

    function busy(on) {
        els.ok.disabled = on;
        els.cancel.disabled = on;
        els.pw.disabled = on;
    }

    function submit() {
        if (!pending) { return; }
        var pw = els.pw.value;
        if (pw === '') { showError('Enter your own password.'); return; }
        busy(true);
        var body = new URLSearchParams();
        body.append('csrf_token', csrf());
        body.append('group', pending.group);
        body.append('password', pw);
        pw = '';
        fetch('api/settings-unlock.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (r) {
            return r.json().catch(function () { return { success: false, error: 'Could not check the password. Try again.' }; });
        }).then(function (data) {
            busy(false);
            if (data && data.success) {
                var group = pending.group;
                finish(true);
                opened(group);
                return;
            }
            showError((data && (data.error || data.message)) || 'That password is not correct.');
        }).catch(function () {
            busy(false);
            showError('No connection. Nothing was unlocked. Try again.');
        });
    }

    function finish(ok) {
        if (!pending) { return; }
        var p = pending;
        pending = null;
        els.pw.value = '';
        busy(false);
        if (dlg.open) { dlg.close(); }
        if (!ok && p.opener && p.opener.focus) { p.opener.focus(); }
        p.resolve(ok);
    }

    function unlock(group, name, opener) {
        build();
        if (pending) { finish(false); }
        return new Promise(function (resolve) {
            pending = { group: group, resolve: resolve, opener: opener || null };
            var n = name || 'these settings';
            els.title.textContent = 'Unlock the ' + n;
            els.text.textContent = 'To change the ' + n + ', enter your own password. They stay open for 10 minutes or until you save, then lock again.';
            els.err.hidden = true;
            els.err.textContent = '';
            els.pw.value = '';
            els.pw.removeAttribute('aria-invalid');
            if (typeof dlg.showModal === 'function') {
                dlg.showModal();
            } else {
                dlg.setAttribute('open', '');
            }
            els.pw.focus();
        });
    }

    window.RhSettingsLock = { unlock: unlock };

    // "Change (password needed)" buttons on Hotel Settings.
    document.addEventListener('click', function (e) {
        var btn = e.target && e.target.closest ? e.target.closest('button[data-settings-unlock]') : null;
        if (!btn) { return; }
        e.preventDefault();
        var group = btn.getAttribute('data-settings-unlock');
        unlock(group, btn.getAttribute('data-settings-unlock-name') || '', btn).then(function (ok) {
            if (!ok) { return; }
            var fs = document.getElementById(btn.getAttribute('aria-controls') || '');
            var first = fs ? fs.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled])') : null;
            if (first) { first.focus(); }
        });
    });
}());
