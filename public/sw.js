/*
 * Minimal by design, because this is a live-score app: a cached scoreboard is
 * a WRONG scoreboard. HTML is never cached — navigations go network-first and
 * fall back to the one precached offline page. Only immutable hashed build
 * assets are served cache-first; brand artefacts revalidate in the background.
 *
 * Bump VERSION whenever the caching strategy or the offline page changes:
 * activate() drops every cache that does not carry the current name.
 */

const VERSION = 'v2';
const CACHE = `cfb-${VERSION}`;
const OFFLINE_URL = '/offline';

/* Paths the worker must never sit between the app and the network. Livewire
 * update/navigate traffic and the admin panel are dynamic by definition, and
 * a worker mediating them adds failure modes without adding value. */
const BYPASS = ['/livewire', '/admin', '/broadcasting', '/webhooks'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            /* Guarded: an unhandled rejection here fails the WHOLE install —
             * no service worker and therefore NO PUSH for that visitor until
             * the next update check. If /offline hiccups, the only thing
             * worth losing is the offline fallback itself. */
            .then((cache) => cache.addAll([OFFLINE_URL]).catch(() => {}))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            /* Stale deploys inside the SAME cache: /build/ files are content-
             * hashed so an old entry is never re-served by URL, but nothing
             * ever evicted them — they accumulated across deploys until
             * quota pressure threatened the whole cache, offline page
             * included. The manifest names the current build; every /build/
             * entry it does not name goes. Failing to fetch the manifest
             * prunes nothing rather than everything. */
            .then(() => Promise.all([
                caches.open(CACHE),
                fetch('/build/manifest.json').then((response) => (response.ok ? response.json() : null)).catch(() => null),
            ]))
            .then(([cache, manifest]) => {
                if (!manifest) return;

                const current = new Set();

                Object.values(manifest).forEach((entry) => {
                    if (entry.file) current.add('/build/' + entry.file);
                    (entry.css || []).forEach((file) => current.add('/build/' + file));
                    (entry.assets || []).forEach((file) => current.add('/build/' + file));
                });

                return cache.keys().then((requests) => Promise.all(
                    requests
                        .filter((request) => {
                            const path = new URL(request.url).pathname;

                            return path.startsWith('/build/') && !current.has(path);
                        })
                        .map((request) => cache.delete(request))
                ));
            })
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

    /* Brand artefacts: the splash URLs carry ?v= cache-busters and the query
     * is part of the cache KEY, so a rebrand misses the old entry outright
     * and a hit is never stale. The background revalidate earns its keep on
     * /favicon.ico — the one un-busted URL this branch serves. */
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

/* Web push. The payload is WebPushMessage::toArray() — flat: `title` plus
 * showNotification options (body, icon, badge, tag, data). No title means a
 * payload we did not send, and we show nothing rather than invent one.
 * VERSION stays untouched by these handlers on purpose: the bump contract
 * above is scoped to the caching strategy and the offline page, and a worker
 * update rides the byte diff on the next navigation regardless. */
self.addEventListener('push', (event) => {
    if (!event.data) return;

    let payload;

    try {
        payload = event.data.json();
    } catch {
        return;
    }

    if (!payload.title) return;

    const { title, ...options } = payload;

    event.waitUntil(self.registration.showNotification(title, options));
});

/* The only real deep link a home-screen web app has: tapping a notification
 * lands INSIDE the installed app, never in a browser tab. Prefer the window
 * that already exists (the manifest's launch_handler makes captured links do
 * the same), and fall back to opening one. */
self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const url = event.notification.data && event.notification.data.url;

    if (!url) return;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const client = windows.find((w) => w.url.startsWith(self.location.origin));

            if (client) {
                return client.focus().then(() => client.navigate(url)).catch(() => clients.openWindow(url));
            }

            return clients.openWindow(url);
        })
    );
});
