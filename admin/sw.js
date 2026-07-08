/**
 * sw.js — Rosalyn's Hotel Admin Service Worker.
 *
 *   Caching strategies:
 *   1. Immutable assets (fonts, images, icons): cache-first — truly static, no version churn.
 *   2. CSS / JS: network-first with cache fallback — must always reflect latest code so
 *      layout and behaviour fixes show immediately on page navigation, not just on F5.
 *   3. Admin HTML pages: network-first with offline fallback.
 *   4. Menu data API GETs: stale-while-revalidate.
 *
 *   POST traffic is NEVER intercepted by the SW; the offline-queue.js client-side
 *   library handles offline POST replay using IndexedDB and the stock_orders.client_uuid
 *   idempotency key. This avoids the SW silently swallowing a write the user thought
 *   succeeded.
 *
 *   Background Sync: when the client calls
 *     navigator.serviceWorker.ready.then(r => r.sync.register('rh-pos-queue'))
 *   the SW fires a 'sync' event even after the tab was closed and reopened. The
 *   handler posts a message back to all clients so offline-queue.js can flush.
 *   Falls back gracefully in browsers without Background Sync support.
 *
 *   IMPORTANT: bumping SW_VERSION deletes all old caches on activate.
 *   Bump it whenever cached assets must be force-refreshed on all clients.
 */
const SW_VERSION = 'rh-admin-v8-2026-07-09';
const ASSET_CACHE = `${SW_VERSION}-assets`;  // fonts, images, icons only
const PAGE_CACHE  = `${SW_VERSION}-pages`;
const DATA_CACHE  = `${SW_VERSION}-data`;

// Cache size limits — prevent unbounded growth on staff devices.
const MAX_PAGE_ENTRIES  = 30;
const MAX_ASSET_ENTRIES = 60;
const MAX_DATA_ENTRIES  = 20;

// Only truly immutable assets (fonts, images, icons) go into the long-lived cache.
// CSS and JS are intentionally excluded so layout/code changes are always live.
const isImmutableAsset = url => /\.(?:woff2?|ttf|eot|svg|png|jpe?g|webp|gif|ico)$/i.test(url.pathname);
// JS and CSS use network-first: always fetch fresh, fall back to cache offline.
const isStyleOrScript  = url => /\.(?:css|js)(\?.*)?$/i.test(url.pathname + url.search);
const isPage           = url => /\/admin\/[^?]*\.php$/.test(url.pathname);
const isData           = url => /\/api\/(?:room-types|page-content|site-settings|room-amenities)\.php/.test(url.pathname);

/** Trim a named cache to at most `max` entries (oldest-first eviction). */
async function trimCache(cacheName, max) {
    try {
        const cache = await caches.open(cacheName);
        const keys  = await cache.keys();
        if (keys.length > max) {
            await Promise.all(keys.slice(0, keys.length - max).map(k => cache.delete(k)));
        }
    } catch (e) { /* non-fatal */ }
}

self.addEventListener('install', e => {
    // Nothing to precache — let assets warm up naturally on first use.
    e.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', e => {
    // Delete every old cache bucket so stale CSS/JS from previous versions is wiped,
    // then apply LRU eviction to the current buckets so devices with large offline
    // histories don't accumulate unbounded storage.
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.map(k => k.startsWith('rh-admin-') && !k.startsWith(SW_VERSION) ? caches.delete(k) : null)
            ))
            .then(() => trimCache(PAGE_CACHE,  MAX_PAGE_ENTRIES))
            .then(() => trimCache(ASSET_CACHE, MAX_ASSET_ENTRIES))
            .then(() => trimCache(DATA_CACHE,  MAX_DATA_ENTRIES))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // POST (and all non-GET) requests MUST NEVER be served from cache.
    // The offline-queue.js library handles POST replay via IndexedDB.
    if (req.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    // --- Immutable assets: cache-first ---
    if (isImmutableAsset(url)) {
        event.respondWith(
            caches.match(req).then(hit => hit || fetch(req).then(resp => {
                if (resp && resp.ok) {
                    const c = resp.clone();
                    caches.open(ASSET_CACHE).then(cs => {
                        cs.put(req, c).catch(() => { });
                        trimCache(ASSET_CACHE, MAX_ASSET_ENTRIES);
                    }).catch(() => { });
                }
                return resp;
            }).catch(() => caches.match(req).then(hit => hit || Response.error())))
        );
        return;
    }

    // --- CSS / JS: network-first so code changes are always live ---
    if (isStyleOrScript(url)) {
        event.respondWith(
            fetch(req)
                .then(resp => {
                    if (resp && resp.ok) {
                        const c = resp.clone();
                        caches.open(ASSET_CACHE).then(cs => {
                            cs.put(req, c).catch(() => { });
                            trimCache(ASSET_CACHE, MAX_ASSET_ENTRIES);
                        }).catch(() => { });
                    }
                    return resp;
                })
                .catch(() => caches.match(req).then(hit => hit || Response.error()))
        );
        return;
    }

    // --- API data: stale-while-revalidate ---
    if (isData(url)) {
        event.respondWith(caches.match(req).then(hit => {
            const network = fetch(req).then(resp => {
                if (resp && resp.ok) {
                    const c = resp.clone();
                    caches.open(DATA_CACHE).then(cs => {
                        cs.put(req, c).catch(() => { });
                        trimCache(DATA_CACHE, MAX_DATA_ENTRIES);
                    }).catch(() => { });
                }
                return resp;
            }).catch(() => hit || Response.error());
            return hit || network;
        }));
        return;
    }

    // --- Admin pages: network-first with offline fallback ---
    if (isPage(url)) {
        event.respondWith(
            fetch(req)
                .then(resp => {
                    if (resp && resp.ok) {
                        const c = resp.clone();
                        caches.open(PAGE_CACHE).then(cs => {
                            cs.put(req, c).catch(() => { });
                            trimCache(PAGE_CACHE, MAX_PAGE_ENTRIES);
                        }).catch(() => { });
                    }
                    return resp;
                })
                .catch(() => caches.match(req)
                    .then(hit => hit || caches.match('/admin/offline.php')
                        .then(offlinePage => offlinePage || new Response(
                            '<!doctype html><meta charset=utf-8><title>Offline</title>' +
                            '<style>body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#1f1f24;color:#F7F3EE;text-align:center}</style>' +
                            '<h1 style="font-size:1.5rem">You\'re offline</h1>' +
                            '<p style="color:#aaa;margin-top:8px">This page is not available offline.</p>',
                            { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                        ))
                    )
                )
        );
        return;
    }
});

self.addEventListener('message', event => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

/**
 * Background Sync handler — 'rh-pos-queue' tag.
 *
 * When the browser fires this sync event (even if the tab was closed and
 * reopened, as long as there is at least one controlled client), we post a
 * 'RH_FLUSH_QUEUE' message to every admin client so offline-queue.js can
 * call flush() without needing the window.online event to have fired.
 *
 * If no client windows are open the sync event is still accepted so the
 * browser marks it done; the queue will flush via window.online when the
 * user next opens any admin page.
 *
 * Browsers without Background Sync support simply never fire this event —
 * the fallback is the existing window.addEventListener('online', flush) in
 * offline-queue.js.
 */
self.addEventListener('sync', event => {
    if (event.tag === 'rh-pos-queue') {
        event.waitUntil(
            self.clients.matchAll({ includeUncontrolled: false, type: 'window' })
                .then(clients => {
                    if (clients.length === 0) return; // no open tabs — flush on next online event
                    clients.forEach(client => client.postMessage({ type: 'RH_FLUSH_QUEUE' }));
                })
        );
    }
});
