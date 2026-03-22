const CACHE_NAME = 'rjournaler-static-v1';
const ASSETS_TO_CACHE = [
    '/',
    '/index.php',
    '/entry.php',
    '/login.php',
    '/assets/weather/',
];

self.addEventListener('install', (event) => {
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_TO_CACHE).catch(() => {}))
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
        ))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    // Let non-GET requests go to network (POSTs like autosave) — client handles offline queuing.
    if (req.method !== 'GET') return;

    // For navigation requests, try network first then fallback to cache.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).then((res) => {
                // Optionally update cache
                const copy = res.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
                return res;
            }).catch(() => caches.match(req).then((cached) => cached || caches.match('/entry.php')))
        );
        return;
    }

    // For other GET requests, serve cache-first then network.
    event.respondWith(
        caches.match(req).then((cached) => cached || fetch(req).then((res) => {
            const copy = res.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(req, copy));
            return res;
        }).catch(() => cached))
    );
});
