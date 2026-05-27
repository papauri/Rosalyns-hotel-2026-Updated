/**
 * public-sw.js — Rosalyn's Hotel public-facing Service Worker.
 *
 * Strategy:
 *  - Static assets (fonts, images): cache-first
 *  - CSS / JS: network-first, cache fallback
 *  - HTML pages: network-first, offline fallback
 *  - POST/non-GET: never intercepted
 */
const SW_VERSION = 'rh-public-v1-2026-05-26-231222-3213';
const ASSET_CACHE = `${SW_VERSION}-assets`;
const PAGE_CACHE = `${SW_VERSION}-pages`;

const OFFLINE_FALLBACK = '/offline.php';

const isImmutableAsset = url => /\.(?:woff2?|ttf|eot|svg|png|jpe?g|webp|gif|ico)$/i.test(url.pathname);
const isStyleOrScript = url => /\.(?:css|js)(\?.*)?$/i.test(url.pathname + url.search);
const isPage = url => /\.php$|^\/$/.test(url.pathname);

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.map(k => (k.startsWith('rh-public-') && k !== SW_VERSION + '-assets' && k !== SW_VERSION + '-pages')
                    ? caches.delete(k) : null)
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // Never intercept non-GET or cross-origin or admin paths
    if (req.method !== 'GET') return;
    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith('/admin/')) return;
    if (url.pathname.startsWith('/api/')) return;

    // Immutable assets — cache-first
    if (isImmutableAsset(url)) {
        event.respondWith(
            caches.match(req).then(hit => hit || fetch(req).then(resp => {
                if (resp && resp.ok) {
                    caches.open(ASSET_CACHE).then(c => c.put(req, resp.clone())).catch(() => { });
                }
                return resp;
            }))
        );
        return;
    }

    // CSS / JS — network-first
    if (isStyleOrScript(url)) {
        event.respondWith(
            fetch(req)
                .then(resp => {
                    if (resp && resp.ok) {
                        caches.open(ASSET_CACHE).then(c => c.put(req, resp.clone())).catch(() => { });
                    }
                    return resp;
                })
                .catch(() => caches.match(req).then(hit => hit || Response.error()))
        );
        return;
    }

    // Public HTML pages — network-first with offline fallback
    if (isPage(url)) {
        event.respondWith(
            fetch(req)
                .then(resp => {
                    if (resp && resp.ok) {
                        caches.open(PAGE_CACHE).then(c => c.put(req, resp.clone())).catch(() => { });
                    }
                    return resp;
                })
                .catch(() => caches.match(req)
                    .then(hit => hit || caches.match(OFFLINE_FALLBACK))
                )
        );
    }
});

self.addEventListener('message', event => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});
