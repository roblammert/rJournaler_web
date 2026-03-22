const STATIC_CACHE = 'rjournaler-static-v2';
const RUNTIME_CACHE = 'rjournaler-runtime-v1';
const PRECACHE_URLS = [
    '/',
    '/index.php',
    '/entry.php',
    '/login.php',
    '/offline.html',
];

// Simple utility: limit cache size by deleting oldest entries
async function trimCache(cacheName, maxEntries) {
    const cache = await caches.open(cacheName);
    const keys = await cache.keys();
    if (keys.length <= maxEntries) return;
    for (let i = 0; i < keys.length - maxEntries; i++) {
        await cache.delete(keys[i]);
    }
}

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE_URLS)).catch(() => {})
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter(k => k !== STATIC_CACHE && k !== RUNTIME_CACHE).map(k => caches.delete(k))
        ))
    );
    self.clients.claim();
});

// Respond strategy:
// - navigation: network-first, fallback to cache then offline.html
// - same-origin API ("/api/"): network-first (don't cache POSTs)
// - static assets: stale-while-revalidate from runtime cache
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Pass through non-GET requests to network (client handles queuing)
    if (req.method !== 'GET') return;

    // Network-first for navigations
    if (req.mode === 'navigate') {
        event.respondWith((async () => {
            try {
                const networkResp = await fetch(req);
                const copy = networkResp.clone();
                const cache = await caches.open(STATIC_CACHE);
                cache.put(req, copy).catch(() => {});
                return networkResp;
            } catch (err) {
                const cached = await caches.match(req);
                if (cached) return cached;
                const offline = await caches.match('/offline.html');
                return offline || new Response('Offline', { status: 503, statusText: 'Offline' });
            }
        })());
        return;
    }

    // API network-first, but don't cache API responses by default
    if (url.pathname.startsWith('/api/')) {
        event.respondWith((async () => {
            try {
                return await fetch(req);
            } catch (err) {
                const cached = await caches.match(req);
                return cached || new Response(JSON.stringify({ error: 'Network unavailable' }), { status: 503, headers: { 'Content-Type': 'application/json' } });
            }
        })());
        return;
    }

    // Stale-while-revalidate for other same-origin static requests
    if (url.origin === self.location.origin) {
        event.respondWith((async () => {
            const cache = await caches.open(RUNTIME_CACHE);
            const cached = await cache.match(req);
            const networkFetch = fetch(req).then((res) => {
                if (res && res.ok) cache.put(req, res.clone());
                return res;
            }).catch(() => null);
            // Return cached if present immediately, otherwise wait for network
            return cached || (await networkFetch) || cached || new Response(null, { status: 404 });
        })());
        // Keep runtime cache trimmed
        event.waitUntil(trimCache(RUNTIME_CACHE, 100));
        return;
    }

    // For cross-origin requests, try network then cache
    event.respondWith(fetch(req).catch(() => caches.match(req)));
});

self.addEventListener('message', (event) => {
    const data = event.data || {};
    if (data && data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (data && data.type === 'CLEAR_CACHES') {
        event.waitUntil(caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k)))));
    }
});
