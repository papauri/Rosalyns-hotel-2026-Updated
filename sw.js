/**
 * sw.js — Root-level service worker stub.
 *
 * This file exists only to handle stale browser registrations that point
 * to /sw.js from an earlier version of the site. It immediately activates,
 * clears any old caches it owns, and unregisters itself so the browser
 * never fetches it again.
 *
 * The real admin service worker is at /admin/sw.js.
 */
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            .then(keys => Promise.all(keys.map(k => caches.delete(k))))
            .then(() => self.registration.unregister())
            .then(() => self.clients.matchAll({ includeUncontrolled: true }))
            .then(clients => clients.forEach(c => c.navigate(c.url)))
    );
});
