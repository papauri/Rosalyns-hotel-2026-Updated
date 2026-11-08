/*
 * Kitchen-screen-device printing for the KDS / BDS / CDS pages.
 * Polls api/print-station.php for the receipt printers set to "Kitchen screen device" for this station. On a screen
 * where staff ticked "This screen prints for <printer>", each new ticket is claimed (the server hands a ticket to
 * one screen only, and remembers it, so a reload never prints it twice), printed with the browser into a hidden
 * frame, and reported back. The panel shows each ticket as Printed / Waiting / Failed with a Reprint button.
 * Works with any printer the device can print to. Silent printing needs Chrome started with --kiosk-printing.
 */
(function () {
    'use strict';
    var cfg = window.RH_PRINT_STATION;
    if (!cfg || !cfg.station || !cfg.csrf) { return; }
    var API = '../api/print-station.php';
    var LS_KEY = 'rh_print_device_' + cfg.station;
    var printers = [];
    var jobs = [];
    var busy = false;
    var btn = null;
    var panel = null;
    var opener = null;

    function openPanel(from) {
        opener = from || btn;
        panel.hidden = false;
        render();
        var c = panel.querySelector('.pp-close');
        if (c) { c.focus(); }
    }
    function closePanel() {
        if (!panel || panel.hidden) { return; }
        panel.hidden = true;
        // Back to whatever opened it; the burger-menu button may be hidden again by now, so fall back to the top bar button.
        var back = (opener && opener.offsetParent !== null) ? opener : btn;
        if (back && typeof back.focus === 'function') { back.focus(); }
    }

    function mine() {
        try { var v = JSON.parse(localStorage.getItem(LS_KEY) || '[]'); return Array.isArray(v) ? v.map(Number) : []; } catch (e) { return []; }
    }
    function setMine(arr) { try { localStorage.setItem(LS_KEY, JSON.stringify(arr)); } catch (e) { /* private mode */ } }

    function post(action, data) {
        var fd = new FormData();
        fd.append('action', action);
        fd.append('csrf_token', cfg.csrf);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(API, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    function ensureUi() {
        if (btn) { return; }
        var right = document.querySelector('.topbar .right');
        btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'toggle';
        btn.id = 'rhPrintBtn';
        btn.setAttribute('data-no-lock', '1');
        btn.innerHTML = '<i class="fas fa-print"></i> <span>Printer</span>';
        btn.addEventListener('click', function () { if (panel.hidden) { openPanel(btn); } else { closePanel(); } });
        if (right) { right.insertBefore(btn, right.firstChild); } else { document.body.appendChild(btn); }
        // On a small tablet the top bar folds into the burger menu: give the same control a place there.
        var links = document.querySelector('#kdsMenuDrawer .drawer-links');
        if (links) {
            var db = document.createElement('button');
            db.type = 'button';
            db.id = 'rhPrintDrawerBtn';
            db.innerHTML = '<i class="fas fa-print"></i> Printer';
            db.addEventListener('click', function () {
                if (typeof window.closeKdsDrawer === 'function') { window.closeKdsDrawer(); }
                openPanel(db);
            });
            links.insertBefore(db, links.firstChild);
        }

        var st = document.createElement('style');
        /* Panel and button use the station tokens from kds.css (fallbacks keep it readable on any station page). */
        st.textContent = '#rhPrintPanel{position:fixed;top:64px;right:12px;z-index:10000;width:min(380px,calc(100vw - 24px));max-height:70vh;overflow:auto;box-sizing:border-box;background:var(--surface,#fffdfb);color:var(--txt,#2a2723);border:1px solid var(--line,#dccfc2);border-radius:var(--r,12px);box-shadow:var(--shadow-2,0 4px 14px rgba(42,39,35,.1));padding:14px;font-size:14px}'
            + '#rhPrintPanel[hidden]{display:none}#rhPrintPanel .pp-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}#rhPrintPanel .pp-close{display:inline-flex;align-items:center;gap:6px}#rhPrintPanel h4{margin:0;font-size:15px;font-weight:600}#rhPrintPanel .pp-help{font-size:12px;line-height:1.4;color:var(--txt-2,#5e554d);margin:8px 0 12px}'
            + '#rhPrintPanel label{display:flex;gap:8px;align-items:center;min-height:44px}#rhPrintPanel label input{width:20px;height:20px;flex:0 0 auto}'
            + '#rhPrintPanel .pp-job{display:flex;gap:12px;align-items:center;justify-content:space-between;border-top:1px solid var(--line-soft,#eae1d8);padding:8px 0}#rhPrintPanel .pp-job>div{min-width:0;overflow-wrap:anywhere}'
            + '#rhPrintPanel .pp-job small{display:block;margin-top:2px;font-size:12px;color:var(--txt-2,#5e554d)}'
            + '#rhPrintPanel .pp-pill{display:inline-block;margin-top:2px;padding:2px 10px;border-radius:999px;font-size:11.5px;font-weight:600;border:1px solid transparent}'
            + '#rhPrintPanel .pp-ok{background:color-mix(in srgb,var(--ready,#3f8f5a) 12%,transparent);border-color:color-mix(in srgb,var(--ready,#3f8f5a) 30%,transparent);color:color-mix(in srgb,var(--ready,#3f8f5a) 60%,var(--txt,#2a2723))}'
            + '#rhPrintPanel .pp-bad{background:color-mix(in srgb,var(--late,#956a5b) 12%,transparent);border-color:color-mix(in srgb,var(--late,#956a5b) 30%,transparent);color:color-mix(in srgb,var(--late,#956a5b) 60%,var(--txt,#2a2723))}'
            + '#rhPrintPanel .pp-wait{background:color-mix(in srgb,var(--new,#9a7c53) 14%,transparent);border-color:color-mix(in srgb,var(--new,#9a7c53) 32%,transparent);color:color-mix(in srgb,var(--new,#9a7c53) 55%,var(--txt,#2a2723))}'
            + '#rhPrintPanel button{min-height:44px;min-width:44px;padding:0 14px;border-radius:var(--r-sm,8px);border:1px solid var(--line,#dccfc2);background:var(--surface-2,#f8f4ef);color:var(--txt-2,#5e554d);font-size:12.5px;font-weight:600;cursor:pointer}#rhPrintPanel button:hover{background:var(--surface-3,#eee6dc);color:var(--txt,#2a2723)}'
            + '#rhPrintBtn.pp-on{color:color-mix(in srgb,var(--ready,#3f8f5a) 75%,var(--txt,#2a2723));border-color:color-mix(in srgb,var(--ready,#3f8f5a) 36%,transparent);background:color-mix(in srgb,var(--ready,#3f8f5a) 10%,transparent)}';
        document.head.appendChild(st);
        panel = document.createElement('div');
        panel.id = 'rhPrintPanel';
        panel.hidden = true;
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-label', 'Printing from this screen');
        document.body.appendChild(panel);
        document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape' && !panel.hidden) { closePanel(); } });
    }

    function statusText(j) {
        if (j.status === 'printed') { return ['Printed', 'pp-ok']; }
        if (j.status === 'failed') { return ['Failed', 'pp-bad']; }
        return ['Waiting', 'pp-wait'];
    }

    function render() {
        if (!panel || panel.hidden) { return; }
        var sel = mine();
        panel.textContent = '';
        var head = document.createElement('div');
        head.className = 'pp-head';
        var h = document.createElement('h4');
        h.textContent = 'Printing from this screen';
        var cl = document.createElement('button');
        cl.type = 'button';
        cl.className = 'pp-close';
        cl.setAttribute('aria-label', 'Close printing panel');
        cl.innerHTML = '<i class="fas fa-xmark" aria-hidden="true"></i><span>Close</span>';
        cl.addEventListener('click', closePanel);
        head.appendChild(h);
        head.appendChild(cl);
        panel.appendChild(head);
        printers.forEach(function (p) {
            var l = document.createElement('label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = sel.indexOf(Number(p.id)) !== -1;
            cb.addEventListener('change', function () {
                var cur = mine().filter(function (x) { return x !== Number(p.id); });
                if (cb.checked) { cur.push(Number(p.id)); }
                setMine(cur);
                updateBtn();
                tick();
            });
            l.appendChild(cb);
            l.appendChild(document.createTextNode('This screen prints for ' + p.name + ' (' + String(p.paper).toUpperCase() + ')'));
            panel.appendChild(l);
        });
        var help = document.createElement('div');
        help.className = 'pp-help';
        help.textContent = 'Tick the box only on the screen that has the printer attached. Any printer this device can print to will do. Silent printing (no pop-up) needs Chrome started with --kiosk-printing (Windows).';
        panel.appendChild(help);
        var any = false;
        jobs.forEach(function (j) {
            any = true;
            var row = document.createElement('div');
            row.className = 'pp-job';
            var left = document.createElement('div');
            var t = document.createElement('div');
            t.textContent = j.title;
            var s = statusText(j);
            var sm = document.createElement('span');
            sm.className = 'pp-pill ' + s[1];
            sm.textContent = s[0];
            left.appendChild(t);
            left.appendChild(sm);
            if (j.status === 'failed' && j.last_error) {
                var er = document.createElement('small');
                er.textContent = j.last_error;
                left.appendChild(er);
            }
            row.appendChild(left);
            if (j.status === 'printed' || j.status === 'failed') {
                var b = document.createElement('button');
                b.type = 'button';
                b.textContent = 'Reprint';
                b.addEventListener('click', function () {
                    b.disabled = true;
                    post('reprint', { job_id: j.id }).then(function () { tick(); });
                });
                row.appendChild(b);
            }
            panel.appendChild(row);
        });
        if (!any) {
            var e = document.createElement('div');
            e.className = 'pp-help';
            e.textContent = 'No tickets yet today.';
            panel.appendChild(e);
        }
    }

    function updateBtn() {
        if (!btn) { return; }
        var on = printers.some(function (p) { return mine().indexOf(Number(p.id)) !== -1; });
        btn.classList.toggle('pp-on', on);
        btn.title = on ? 'This screen prints tickets' : 'Set up printing from this screen';
    }

    function printHtml(html) {
        return new Promise(function (resolve, reject) {
            var f = document.createElement('iframe');
            f.setAttribute('aria-hidden', 'true');
            f.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;visibility:hidden';
            var finished = false;
            function done(ok, err) {
                if (finished) { return; }
                finished = true;
                setTimeout(function () { if (f.parentNode) { f.parentNode.removeChild(f); } }, 2000);
                ok ? resolve() : reject(err);
            }
            f.onload = function () {
                try {
                    var w = f.contentWindow;
                    w.addEventListener('afterprint', function () { done(true); });
                    w.focus();
                    w.print();
                    setTimeout(function () { done(true); }, 4000); // some browsers never fire afterprint
                } catch (e) { done(false, e); }
            };
            f.srcdoc = html;
            document.body.appendChild(f);
        });
    }

    function drain(ids) {
        if (busy || !ids.length) { return Promise.resolve(); }
        busy = true;
        var chain = Promise.resolve();
        ids.forEach(function (pid) {
            chain = chain.then(function loop() {
                return post('claim', { printer_id: pid }).then(function (r) {
                    if (!r || !r.ok || !r.job) { return null; }
                    return printHtml(r.job.html).then(function () {
                        return post('done', { job_id: r.job.id, ok: 1 });
                    }, function (err) {
                        return post('done', { job_id: r.job.id, ok: 0, error: String(err && err.message || err || 'print failed') });
                    }).then(loop);
                });
            });
        });
        return chain.catch(function () { /* next poll tries again */ }).then(function () { busy = false; });
    }

    function tick() {
        post('poll', { station: cfg.station }).then(function (r) {
            if (!r || !r.ok) { return; }
            printers = r.printers || [];
            jobs = r.jobs || [];
            if (printers.length) { ensureUi(); updateBtn(); render(); }
            var ids = mine().filter(function (id) { return printers.some(function (p) { return Number(p.id) === id; }); });
            return drain(ids).then(function () {
                if (ids.length) { return post('poll', { station: cfg.station }).then(function (r2) { if (r2 && r2.ok) { jobs = r2.jobs || []; render(); } }); }
            });
        }).catch(function () { /* offline: try again on the next poll */ });
    }

    tick();
    setInterval(tick, 5000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });
})();
