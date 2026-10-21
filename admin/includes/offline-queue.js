/**
 * offline-queue.js — Offline POST replay queue for admin POS/KDS/Stock pages.
 *
 *   Capabilities:
 *   - Registers /admin/sw.js for offline page/asset caching.
 *   - When `navigator.onLine === false`, intercepts FORM submissions on opted-in
 *     forms (those with `data-offline-queue="1"`) and stores them in an IndexedDB
 *     store ("rh_pos_queue"). Adds a `client_uuid` field so server-side dedupes.
 *   - Shows a fixed banner with online/offline status and the pending replay count.
 *   - On `online`, replays each queued POST using `fetch(url, { method:'POST', body, credentials:'include' })`.
 *
 *   Server-side contract: forms targeting endpoints that need idempotency (e.g.
 *   `pos.php?action=park|place_order|park_pay`) MUST honour the `client_uuid` field
 *   so a replay does not double-charge.
 */
(function () {
    if (!('serviceWorker' in navigator) || !('indexedDB' in window)) return;

    const DB_NAME = 'rh_admin_offline_v1';
    const STORE = 'pos_queue';

    function openDb() {
        return new Promise((res, rej) => {
            const req = indexedDB.open(DB_NAME, 1);
            req.onupgradeneeded = () => req.result.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true });
            req.onsuccess = () => res(req.result);
            req.onerror = () => rej(req.error);
        });
    }
    async function txStore(mode) {
        const db = await openDb();
        return db.transaction(STORE, mode).objectStore(STORE);
    }
    async function enqueue(entry) {
        const s = await txStore('readwrite');
        return new Promise((res, rej) => { const r = s.add(entry); r.onsuccess = () => res(r.result); r.onerror = () => rej(r.error); });
    }
    async function listAll() {
        const s = await txStore('readonly');
        return new Promise((res, rej) => { const r = s.getAll(); r.onsuccess = () => res(r.result || []); r.onerror = () => rej(r.error); });
    }
    async function deleteEntry(id) {
        const s = await txStore('readwrite');
        return new Promise((res, rej) => { const r = s.delete(id); r.onsuccess = () => res(); r.onerror = () => rej(r.error); });
    }
    async function count() {
        const s = await txStore('readonly');
        return new Promise((res, rej) => { const r = s.count(); r.onsuccess = () => res(r.result || 0); r.onerror = () => rej(r.error); });
    }

    /* Who is signed in on this page (printed by offline-banner.php), and whose queued entries these are.
     * An entry records the person who took it so it is only ever replayed under THAT person's session
     * (the server stamps created_by/paid_by from the session, so a replay by someone else would put the
     * cash in the wrong shift). Entries with no recorded person (queued before this) behave as before. */
    function curUser() {
        const u = window.RH_OFFLINE_USER;
        return (u && Number(u.id) > 0) ? { id: Number(u.id), name: String(u.name || '') } : null;
    }
    function isMine(it) {
        const u = curUser();
        return !it.uid || !u || Number(it.uid) === u.id;
    }
    /* { mine: entries this person may send, others: entries held for someone else, names: who } */
    async function stats() {
        const all = await listAll();
        const mine = [], others = [], names = [];
        for (const it of all) {
            if (isMine(it)) { mine.push(it); } else { others.push(it); const n = it.uname || ('user #' + it.uid); if (names.indexOf(n) < 0) names.push(n); }
        }
        return { mine, others, names };
    }
    function heldText(st) {
        const n = st.others.length;
        return n + ' sale' + (n === 1 ? '' : 's') + ' waiting for ' + st.names.join(', ') + ' to sign in on this tablet';
    }

    function uuid() {
        return (crypto && crypto.randomUUID) ? crypto.randomUUID() : 'cli-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
    }

    /* True when a replayed form was sent straight back to the address it was posted from: the way
     * payment-add.php refuses a bad security token. The till always lands on pos.php?pos_saved=1 after
     * a sale (a form queued from that same address included), so that is never a refusal. */
    function bouncedBack(finalUrl, postedUrl) {
        try {
            const x = new URL(finalUrl, location.href), y = new URL(postedUrl, location.href);
            return x.pathname === y.pathname && x.search === y.search && !/[?&]pos_saved=1(?:&|$)/.test(x.search);
        } catch (e) { return false; }
    }

    /* Non-blocking notification — uses the admin toast system when available;
     * never window.alert(), which freezes the till mid-service. */
    function notify(msg, type) {
        if (typeof Alert !== 'undefined' && Alert.show) { Alert.show(msg, type || 'info'); return; }
        const label = document.getElementById('rhOfflineLabel');
        if (label) { label.textContent = msg; }
    }

    /* ── Header connectivity pill ────────────────────────────────────────────
     * The <div id="rhConnPill"> is injected by admin-header.php.
     * We update its colours + label in real-time as the network state changes.
     * Pill shows: Online (green) | Offline (red) | Syncing N… (amber)
     */
    function updatePill(online, queued, held) {
        const pill = document.getElementById('rhConnPill');
        const dot = document.getElementById('rhConnDot');
        const label = document.getElementById('rhConnLabel');
        if (!pill) return;
        if (!online) {
            pill.style.background = '#fee2e2'; pill.style.color = '#991b1b';
            if (dot) dot.style.color = '#dc2626';
            if (label) label.textContent = 'Offline';
            pill.title = 'No internet connection — submissions will be queued locally';
        } else if (queued > 0) {
            pill.style.background = '#fef3c7'; pill.style.color = '#92400e';
            if (dot) dot.style.color = '#f59e0b';
            if (label) label.textContent = `Syncing ${queued}…`;
            pill.title = `${queued} queued item(s) syncing now`;
        } else if (held) {
            pill.style.background = '#fef3c7'; pill.style.color = '#92400e';
            if (dot) dot.style.color = '#f59e0b';
            if (label) label.textContent = 'Held for ' + held.names.join(', ');
            pill.title = heldText(held);
        } else {
            pill.style.background = '#d1fae5'; pill.style.color = '#065f46';
            if (dot) dot.style.color = '#059669';
            if (label) label.textContent = 'Online';
            pill.title = 'Connected';
        }
    }

    /* ── Bottom toast banner — shown only when there are queued items ────────
     * (No longer shown just for being "offline" — the header pill handles that.)
     */
    const css = document.createElement('style');
    css.textContent = `
      #rhOfflineBanner { position:fixed; bottom:0; left:0; right:0; z-index:99999; background:#1f2937; color:#fff; padding:8px 16px; font:600 13px/1.4 system-ui, sans-serif; display:none; align-items:center; justify-content:space-between; box-shadow:0 -2px 8px rgba(0,0,0,.25); }
      #rhOfflineBanner.syncing { background:#065f46; }
      #rhOfflineBanner .rh-badge { background:#fff3; padding:2px 8px; border-radius:999px; margin-left:8px; }
    `;
    document.head.appendChild(css);
    const banner = document.createElement('div');
    banner.id = 'rhOfflineBanner';
    banner.innerHTML = `<span><strong id="rhOfflineLabel">Syncing</strong> — sending queued actions to server. <span class="rh-badge" id="rhOfflinePending">0 queued</span><span class="rh-badge" id="rhOfflineHeld" style="display:none"></span></span><button id="rhOfflineRetry" type="button" style="background:#fff3;color:#fff;border:0;padding:4px 10px;border-radius:4px;cursor:pointer">Retry now</button>`;
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(banner));

    async function refreshBanner() {
        const st = await stats().catch(() => ({ mine: [], others: [], names: [] }));
        const n = st.mine.length;
        const held = st.others.length > 0 ? st : null;
        const pending = document.getElementById('rhOfflinePending');
        const heldEl = document.getElementById('rhOfflineHeld');

        // Update header pill
        updatePill(navigator.onLine, n, held);

        // Bottom toast: show when offline OR when there are queued items pending sync OR held for someone else
        if (!navigator.onLine || n > 0 || held) {
            banner.className = !navigator.onLine ? '' : 'syncing';
            banner.style.display = 'flex';
            const lbl = document.getElementById('rhOfflineLabel');
            if (!navigator.onLine && n === 0) {
                lbl.textContent = 'You are offline';
            } else if (!navigator.onLine && n > 0) {
                lbl.textContent = 'Offline — queued';
            } else if (n > 0) {
                lbl.textContent = 'Back online — syncing';
            } else {
                lbl.textContent = 'Held';
            }
        } else {
            banner.style.display = 'none';
        }
        if (pending) pending.textContent = `${n} queued`;
        if (heldEl) { heldEl.style.display = held ? '' : 'none'; heldEl.textContent = held ? heldText(held) : ''; }
    }

    let flushing = null;

    function flush() {
        if (!navigator.onLine) return Promise.resolve();
        // Concurrency guard: 'online' event, SW Background Sync message and the
        // "Retry now" button can all fire together — a parallel flush would
        // replay the same entry twice before the first deleteEntry lands.
        // A second caller (sign-out) gets the run already in progress to wait on.
        if (flushing) return flushing;
        flushing = doFlush().finally(() => { flushing = null; });
        return flushing;
    }

    async function doFlush() {
        let rejected = 0;
        try {
            // Only this person's entries; another person's wait for them to sign in (see isMine).
            const items = (await stats()).mine;
            // The CSRF token saved with a queued form belongs to the session it was taken in. If the
            // person has since signed in again the old token is refused and the handler answers 200
            // with an error, which used to be taken as success and the sale dropped. Always send the
            // token of the session that is replaying it now (printed on every admin page).
            const freshCsrf = window.RH_OFFLINE_CSRF || window._rhCsrf || '';
            for (const it of items) {
                try {
                    const fd = new FormData();
                    for (const [k, v] of (it.fields || [])) fd.append(k, (k === 'csrf_token' && freshCsrf) ? freshCsrf : v);
                    // A request that stalls on a weak signal must not hold the queue (or sign-out) for ever:
                    // give up after 20s and treat it as a network failure. If the server did record it, the
                    // next replay is matched on client_uuid and answered "already recorded".
                    const ctl = new AbortController();
                    const ctlTimer = setTimeout(() => ctl.abort(), 20000);
                    let resp;
                    try {
                        resp = await fetch(it.url, { method: 'POST', body: fd, credentials: 'include', signal: ctl.signal });
                    } finally {
                        clearTimeout(ctlTimer);
                    }
                    // Session expired: the POST was redirected to the login page and the
                    // write NEVER happened — the 200 belongs to login.php, not our handler.
                    // Keep the item and stop; it will replay after the user logs back in.
                    if (resp.redirected && /login\.php/i.test(resp.url)) {
                        notify('Session expired — log in again to sync ' + items.length + ' queued action(s).', 'error');
                        break;
                    }
                    if (resp.status === 409) {
                        // 409 Conflict means server already processed this UUID (duplicate) — safe to drop.
                        await deleteEntry(it.id);
                    } else if (resp.status >= 500) {
                        // Server error — keep item in queue and stop flushing; retry on next online event.
                        break;
                    } else if (resp.ok && resp.redirected && !bouncedBack(resp.url, it.url)) {
                        // Every queued form (till payment, new booking, payment) redirects to its result
                        // page on success, and a repeat of an already-recorded one redirects too. A redirect
                        // straight back to the address it was posted to is a refusal (payment-add.php does
                        // that for a bad security token), so it falls through to the kept-and-warned branch.
                        await deleteEntry(it.id);
                    } else {
                        // A 200 with no redirect means the handler re-showed the form with an error, and a
                        // 4xx means it refused the request: the sale/payment was NOT recorded. Never drop it
                        // silently - keep it on this device, carry on with the rest, and tell the person.
                        rejected++;
                    }
                } catch (e) {
                    // Network failure — stop flushing; item stays in queue.
                    break;
                }
            }
        } catch (e) {
            // IndexedDB unreadable: nothing to send this time.
        }
        if (rejected > 0) {
            notify(rejected + ' queued action(s) were refused by the server and are still saved on this device - nothing was recorded for them. Ask a manager before taking the money again.', 'error');
        }
        refreshBanner();
    }

    document.addEventListener('submit', async function (e) {
        const f = e.target;
        if (!(f instanceof HTMLFormElement)) return;
        if (f.dataset.offlineQueue !== '1') return;
        if (navigator.onLine) return; // online -> normal submit
        e.preventDefault();
        // Guarantee a client_uuid for idempotent replay
        if (!f.querySelector('[name="client_uuid"]')) {
            const h = document.createElement('input'); h.type = 'hidden'; h.name = 'client_uuid'; h.value = uuid(); f.appendChild(h);
        }
        // Stamp the moment the user submitted (offline) so the audit log can show queue lag
        if (!f.querySelector('[name="client_queued_at"]')) {
            const ts = document.createElement('input'); ts.type = 'hidden'; ts.name = 'client_queued_at';
            ts.value = (window.rhNowSql ? window.rhNowSql() : new Date().toISOString().slice(0, 19).replace('T', ' '));
            f.appendChild(ts);
        }
        const fd = new FormData(f);
        const fields = []; for (const [k, v] of fd.entries()) fields.push([k, typeof v === 'string' ? v : '']);
        const who = curUser();
        await enqueue({ url: new URL(f.getAttribute('action') || '', location.href).href, /* getAttribute: an input named action would shadow f.action */ fields, when: Date.now(), uid: who ? who.id : 0, uname: who ? who.name : '' });
        refreshBanner();
        notify('You are offline — action saved locally and will sync automatically when the connection returns.', 'info');
    }, true);

    window.addEventListener('online', () => { flush(); refreshBanner(); });
    window.addEventListener('offline', () => {
        refreshBanner();
        // Register a Background Sync tag so the SW can wake the queue even if the
        // tab was closed and reopened before connectivity was restored.
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.ready.then(reg => {
                if (reg.sync) {
                    reg.sync.register('rh-pos-queue').catch(() => { /* non-fatal */ });
                }
            }).catch(() => { });
        }
    });

    // Register SW — derive path from this script's own URL so subdirectory installs work
    (function () {
        var scripts = document.querySelectorAll('script[src*="offline-queue.js"]');
        var swUrl = '/admin/sw.js'; // fallback
        if (scripts.length) {
            try {
                // e.g. https://host/sub/admin/includes/offline-queue.js → https://host/sub/admin/sw.js
                swUrl = new URL('../sw.js', scripts[scripts.length - 1].src).href;
            } catch (e) { }
        }
        navigator.serviceWorker.register(swUrl).then(() => refreshBanner()).catch(() => { });
    }());

    // Listen for Background Sync wake-up message from the SW ('rh-pos-queue' sync event).
    // The SW posts { type: 'RH_FLUSH_QUEUE' } to all controlled clients so we can flush
    // even if the window.online event fired while the tab was not focused.
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('message', event => {
            if (event.data && event.data.type === 'RH_FLUSH_QUEUE') {
                flush();
            }
        });
    }

    // On every page load: refresh the banner AND flush any leftover queue.
    // Without this, items queued before a tab close only replay after another
    // offline→online transition (or Background Sync, which not all browsers have).
    document.addEventListener('DOMContentLoaded', function () {
        refreshBanner();
        if (navigator.onLine) { stats().then(function (st) { if (st.mine.length > 0) flush(); }).catch(function () { }); }
    });
    document.addEventListener('click', e => { if (e.target && e.target.id === 'rhOfflineRetry') flush(); });

    /* ── Send before signing out ─────────────────────────────────────────────
     * Signing out while this person still has unsent sales would leave them for the next person, who
     * can only hold them (isMine) - the cash would stay unsent. So a sign-out click first tries to send;
     * if anything is left (offline or refused) it is stopped with a notice, and a second click within
     * 10 seconds goes ahead anyway (the entries stay held for this person). Plain links to logout.php
     * on every admin/till/kitchen page, so one delegated listener covers them all. */
    let signOutNoticeAt = 0;
    let signOutToast = null;
    function showSignOutNotice(n) {
        if (!signOutToast) {
            signOutToast = document.createElement('div');
            signOutToast.id = 'rhSignOutNotice';
            signOutToast.setAttribute('role', 'alert');
            signOutToast.style.cssText = 'position:fixed;left:50%;transform:translateX(-50%);bottom:56px;z-index:100000;max-width:92vw;background:#92400e;color:#fff;padding:10px 16px;border-radius:8px;font:600 14px/1.4 system-ui,sans-serif;box-shadow:0 4px 16px rgba(0,0,0,.35)';
            document.body.appendChild(signOutToast);
        }
        signOutToast.textContent = 'You have ' + n + ' unsent sale' + (n === 1 ? '' : 's') + ' on this tablet. Stay signed in until they send. (Tap Sign out again within 10 seconds to leave anyway.)';
        signOutToast.style.display = 'block';
        clearTimeout(showSignOutNotice.t);
        showSignOutNotice.t = setTimeout(() => { signOutToast.style.display = 'none'; }, 10000);
    }
    async function guardSignOut(go) {
        // Armed before any waiting, so a second tap within 10s always signs out, even mid-send.
        signOutNoticeAt = Date.now();
        const leave = () => { signOutNoticeAt = 0; go(); };
        try {
            let st = await stats();
            if (st.mine.length > 0 && navigator.onLine) {
                // Wait at most 8s for the send; anything still unsent counts as unsent.
                await Promise.race([flush(), new Promise(r => setTimeout(r, 8000))]);
                st = await stats();
            }
            if (st.mine.length === 0) { leave(); return; }
            signOutNoticeAt = Date.now();
            showSignOutNotice(st.mine.length);
            refreshBanner();
        } catch (err) { leave(); }
    }
    document.addEventListener('click', function (e) {
        const a = e.target && e.target.closest ? e.target.closest('a[href*="logout.php"]') : null;
        if (!a || e.defaultPrevented || e.button > 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
        if (signOutNoticeAt && Date.now() - signOutNoticeAt < 10000) return; // second click: let it go
        e.preventDefault(); e.stopImmediatePropagation();
        guardSignOut(() => { location.href = a.href; });
    }, true);
    document.addEventListener('submit', function (e) {
        const f = e.target;
        if (!(f instanceof HTMLFormElement) || !/logout\.php/i.test(f.getAttribute('action') || '')) return;
        if (signOutNoticeAt && Date.now() - signOutNoticeAt < 10000) return;
        e.preventDefault(); e.stopImmediatePropagation();
        guardSignOut(() => { f.submit(); });
    }, true);
})();
