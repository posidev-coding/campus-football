/*
 * Minimal by design, because this is a live-score app: a cached scoreboard is
 * a WRONG scoreboard. HTML is never cached — navigations go network-first and
 * fall back to the one precached offline page. Only immutable hashed build
 * assets are served cache-first; brand artefacts revalidate in the background.
 *
 * Bump VERSION whenever the caching strategy or the offline page changes:
 * activate() drops every cache that does not carry the current name.
 */

const VERSION = 'v1';
const CACHE = `cfb-${VERSION}`;
const OFFLINE_URL = '/offline';

/* Paths the worker must never sit between the app and the network. Livewire
 * update/navigate traffic and the admin panel are dynamic by definition, and
 * a worker mediating them adds failure modes without adding value. */
const BYPASS = ['/livewire', '/admin', '/broadcasting', '/webhooks'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL])).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    if (request.method !== 'GET') return;

    const url = new URL(request.url);

    if (url.origin !== self.location.origin) return;
    if (BYPASS.some((path) => url.pathname.startsWith(path))) return;

    /* A real navigation (wire:navigate hops are plain fetches and pass through
     * untouched): the network is the truth, the offline page is the floor. */
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match(OFFLINE_URL))
        );
        return;
    }

    /* Vite output is content-hashed — a hit can never be stale. */
    if (url.pathname.startsWith('/build/')) {
        event.respondWith(
            caches.match(request).then((hit) => hit ?? fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            }))
        );
        return;
    }

    /* Brand artefacts carry ?v= cache-busters, so serving a hit while
     * revalidating behind it can only be stale for one load. */
    if (url.pathname.startsWith('/brand/') || url.pathname === '/favicon.ico') {
        event.respondWith(
            caches.match(request).then((hit) => {
                const refresh = fetch(request).then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE).then((cache) => cache.put(request, copy));
                    }

                    return response;
                }).catch(() => hit);

                return hit ?? refresh;
            })
        );
    }
});
