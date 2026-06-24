/**
 * public-sw.js — Rosalyn's Hotel public-facing Service Worker.
 *
 * Strategy:
 *  - Static assets (fonts, images): cache-first
 *  - CSS / JS: network-first, cache fallback
 *  - HTML pages: network-first, offline fallback → /offline.php
 *  - POST/non-GET: NEVER intercepted — pass straight to network
 *
 * BUMP SW_VERSION whenever cached assets must be force-refreshed on all clients.
 */
const SW_VERSION = 'rh-public-v3-2026-06-24';
const ASSET_CACHE = `${SW_VERSION}-assets`;
const PAGE_CACHE  = `${SW_VERSION}-pages`;

// Maximum number of entries kept in the page / asset caches to prevent unbounded growth.
const MAX_PAGE_CACHE_ENTRIES  = 40;
const MAX_ASSET_CACHE_ENTRIES = 80;

// Derive base path from this SW's own URL so the worker is subdirectory-install-safe.
// e.g. if SW is at /rosalyns-hotel/public-sw.js → SW_BASE = '/rosalyns-hotel/'
const SW_BASE = self.location.pathname.replace(/\/[^/]*$/, '/');

const OFFLINE_FALLBACK = SW_BASE + 'offline.php';

const isImmutableAsset = url => /\.(?:woff2?|ttf|eot|svg|png|jpe?g|webp|gif|ico)$/i.test(url.pathname);
const isStyleOrScript  = url => /\.(?:css|js)(\?.*)?$/i.test(url.pathname + url.search);
// Match .php pages AND directory-style URLs (e.g. / /rooms/ /about/)
const isPage           = url => /\.php(\?.*)?$/.test(url.pathname) || /\/$/.test(url.pathname);

self.addEventListener('install', e => {
    // Precache the offline fallback page so it is always available, even on first visit.
    e.waitUntil(
        caches.open(PAGE_CACHE)
            .then(c => c.add(OFFLINE_FALLBACK))
            .catch(() => { /* non-fatal if server unreachable at install time */ })
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.map(k => {
                    // Delete anything in the 'rh-public-' namespace that isn't our current buckets.
                    if (k === ASSET_CACHE || k === PAGE_CACHE) return null;
                    if (k.startsWith('rh-public-')) return caches.delete(k);
                    return null;
                })
            ))
            // LRU eviction: trim current caches to their limits after an SW update
            // so stale entries from the previous version don't bloat the device.
            .then(() => trimCache(PAGE_CACHE,  MAX_PAGE_CACHE_ENTRIES))
            .then(() => trimCache(ASSET_CACHE, MAX_ASSET_CACHE_ENTRIES))
            .then(() => self.clients.claim())
    );
});

/** Trim a cache to at most `max` entries (oldest-first eviction). */
async function trimCache(cacheName, max) {
    const cache = await caches.open(cacheName);
    const keys  = await cache.keys();
    if (keys.length > max) {
        // Delete oldest entries beyond the limit
        await Promise.all(keys.slice(0, keys.length - max).map(k => cache.delete(k)));
    }
}

self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // POST (and all non-GET) requests MUST NEVER be served from cache.
    // Pass them directly to the network without any SW interception.
    if (req.method !== 'GET') return;
    // Never intercept cross-origin, admin, or API paths
    if (url.origin !== self.location.origin) return;
    if (url.pathname.startsWith(SW_BASE + 'admin/')) return;
    if (url.pathname.startsWith(SW_BASE + 'api/')) return;

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
                        caches.open(PAGE_CACHE).then(c => {
                            c.put(req, resp.clone()).catch(() => { });
                            // Evict oldest pages so the cache doesn't grow unbounded
                            trimCache(PAGE_CACHE, MAX_PAGE_CACHE_ENTRIES).catch(() => { });
                        }).catch(() => { });
                    }
                    return resp;
                })
                .catch(() => caches.match(req)
                    .then(hit => hit || caches.match(OFFLINE_FALLBACK))
                    .then(hit => hit || Response.error())
                )
        );
        return;
    }
});

self.addEventListener('message', event => {
    if (event.data === 'SKIP_WAITING') self.skipWaiting();
});
