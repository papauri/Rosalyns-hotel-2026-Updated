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

    function uuid() {
        return (crypto && crypto.randomUUID) ? crypto.randomUUID() : 'cli-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
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
    function updatePill(online, queued) {
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
    banner.innerHTML = `<span><strong id="rhOfflineLabel">Syncing</strong> — sending queued actions to server. <span class="rh-badge" id="rhOfflinePending">0 queued</span></span><button id="rhOfflineRetry" type="button" style="background:#fff3;color:#fff;border:0;padding:4px 10px;border-radius:4px;cursor:pointer">Retry now</button>`;
    document.addEventListener('DOMContentLoaded', () => document.body.appendChild(banner));

    async function refreshBanner() {
        const n = await count().catch(() => 0);
        const pending = document.getElementById('rhOfflinePending');

        // Update header pill
        updatePill(navigator.onLine, n);

        // Bottom toast: show when offline OR when there are queued items pending sync
        if (!navigator.onLine || n > 0) {
            banner.className = !navigator.onLine ? '' : 'syncing';
            banner.style.display = 'flex';
            if (!navigator.onLine && n === 0) {
                document.getElementById('rhOfflineLabel').textContent = 'You are offline';
            } else if (!navigator.onLine && n > 0) {
                document.getElementById('rhOfflineLabel').textContent = 'Offline — queued';
            } else {
                document.getElementById('rhOfflineLabel').textContent = 'Back online — syncing';
            }
        } else {
            banner.style.display = 'none';
        }
        if (pending) pending.textContent = `${n} queued`;
    }

    let isFlushing = false;

    async function flush() {
        if (!navigator.onLine) return;
        // Concurrency guard: 'online' event, SW Background Sync message and the
        // "Retry now" button can all fire together — a parallel flush would
        // replay the same entry twice before the first deleteEntry lands.
        if (isFlushing) return;
        isFlushing = true;
        try {
            const items = await listAll();
            for (const it of items) {
                try {
                    const fd = new FormData();
                    for (const [k, v] of (it.fields || [])) fd.append(k, v);
                    const resp = await fetch(it.url, { method: 'POST', body: fd, credentials: 'include' });
                    // Session expired: the POST was redirected to the login page and the
                    // write NEVER happened — the 200 belongs to login.php, not our handler.
                    // Keep the item and stop; it will replay after the user logs back in.
                    if (resp.redirected && /login\.php/i.test(resp.url)) {
                        notify('Session expired — log in again to sync ' + items.length + ' queued action(s).', 'error');
                        break;
                    }
                    if (resp.ok || resp.status === 409) {
                        // 409 Conflict means server already processed this UUID (duplicate) — safe to drop.
                        await deleteEntry(it.id);
                    } else if (resp.status >= 500) {
                        // Server error — keep item in queue and stop flushing; retry on next online event.
                        break;
                    } else if (resp.status === 302 || (resp.status >= 300 && resp.status < 400)) {
                        // Redirect — treated as success (form handler redirects on success).
                        await deleteEntry(it.id);
                    }
                    // 4xx errors other than 409 (e.g. 400 bad request, 403 forbidden) — item is
                    // unrecoverable; remove it so it doesn't block the queue forever.
                    else {
                        await deleteEntry(it.id);
                    }
                } catch (e) {
                    // Network failure — stop flushing; item stays in queue.
                    break;
                }
            }
        } finally {
            isFlushing = false;
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
            ts.value = new Date().toISOString().slice(0, 19).replace('T', ' ');
            f.appendChild(ts);
        }
        const fd = new FormData(f);
        const fields = []; for (const [k, v] of fd.entries()) fields.push([k, typeof v === 'string' ? v : '']);
        await enqueue({ url: f.action || location.href, fields, when: Date.now() });
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
        if (navigator.onLine) { count().then(function (n) { if (n > 0) flush(); }).catch(function () { }); }
    });
    document.addEventListener('click', e => { if (e.target && e.target.id === 'rhOfflineRetry') flush(); });
})();
