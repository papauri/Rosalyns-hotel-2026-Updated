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
 *   IMPORTANT: bumping SW_VERSION deletes all old caches on activate.
 *   Bump it whenever cached assets must be force-refreshed on all clients.
 */
const SW_VERSION = 'rh-admin-v5-2026-05-26-231222-3213';
const ASSET_CACHE = `${SW_VERSION}-assets`;  // fonts, images, icons only
const PAGE_CACHE = `${SW_VERSION}-pages`;
const DATA_CACHE = `${SW_VERSION}-data`;

// Only truly immutable assets (fonts, images, icons) go into the long-lived cache.
// CSS and JS are intentionally excluded so layout/code changes are always live.
const isImmutableAsset = url => /\.(?:woff2?|ttf|eot|svg|png|jpe?g|webp|gif|ico)$/i.test(url.pathname);
// JS and CSS use network-first: always fetch fresh, fall back to cache offline.
const isStyleOrScript = url => /\.(?:css|js)(\?.*)?$/i.test(url.pathname + url.search);
const isPage = url => /\/admin\/[^?]*\.php$/.test(url.pathname);
const isData = url => /\/api\/(?:room-types|page-content|site-settings|room-amenities)\.php/.test(url.pathname);

self.addEventListener('install', e => {
    // Nothing to precache — let assets warm up naturally on first use.
    e.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', e => {
    // Delete every old cache bucket so stale CSS/JS from v3 or earlier is wiped.
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.map(k => k.startsWith('rh-admin-') && !k.startsWith(SW_VERSION) ? caches.delete(k) : null)
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);
    if (req.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;

    // --- Immutable assets: cache-first ---
    if (isImmutableAsset(url)) {
        event.respondWith(
            caches.match(req).then(hit => hit || fetch(req).then(resp => {
                if (resp && resp.ok) {
                    const c = resp.clone();
                    caches.open(ASSET_CACHE).then(cs => cs.put(req, c)).catch(() => { });
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
                        caches.open(ASSET_CACHE).then(cs => cs.put(req, c)).catch(() => { });
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
                    caches.open(DATA_CACHE).then(cs => cs.put(req, c)).catch(() => { });
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
                        caches.open(PAGE_CACHE).then(cs => cs.put(req, c)).catch(() => { });
                    }
                    return resp;
                })
                .catch(() => caches.match(req).then(hit => hit || new Response(
                    '<!doctype html><meta charset=utf-8><title>Offline</title><h1>Offline</h1><p>This page is not available offline yet.</p>',
                    { headers: { 'Content-Type': 'text/html; charset=utf-8' } }
                )))
        );
        return;
    }
});

self.addEventListener('message', event => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});
