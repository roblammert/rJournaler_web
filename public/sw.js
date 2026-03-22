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

// IndexedDB helpers in service worker scope to access pending autosaves
function idbOpenSW() {
    return new Promise((resolve, reject) => {
        const req = indexedDB.open('rjournaler-db', 1);
        req.onupgradeneeded = () => {
            const db = req.result;
            if (!db.objectStoreNames.contains('autosaves')) {
                db.createObjectStore('autosaves', { keyPath: 'id', autoIncrement: true });
            }
        };
        req.onsuccess = () => resolve(req.result);
        req.onerror = () => reject(req.error);
    });
}

async function idbGetAllPendingSW() {
    const db = await idbOpenSW();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('autosaves', 'readonly');
        const store = tx.objectStore('autosaves');
        const req = store.getAll();
        req.onsuccess = () => resolve(req.result || []);
        req.onerror = () => reject(req.error);
    });
}

async function idbDeletePendingSW(id) {
    const db = await idbOpenSW();
    return new Promise((resolve, reject) => {
        const tx = db.transaction('autosaves', 'readwrite');
        const store = tx.objectStore('autosaves');
        const req = store.delete(id);
        req.onsuccess = () => resolve();
        req.onerror = () => reject(req.error);
    });
}

async function flushPendingFromSW() {
    try {
        const items = await idbGetAllPendingSW();
        let successes = 0;
        let remaining = 0;
        for (const row of items.sort((a,b) => (a.id - b.id))) {
            try {
                const resp = await fetch('/api/entry-autosave.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(row.data)
                });
                if (!resp.ok) {
                    row.data.retries = (row.data.retries || 0) + 1;
                    await idbUpdatePendingSW(row.id, row.data);
                    remaining++;
                    continue;
                }
                const data = await resp.json();
                if (data && data.ok) {
                    await idbDeletePendingSW(row.id);
                    successes++;
                }
            } catch (e) {
                // increment retry and continue
                try {
                    row.data.retries = (row.data.retries || 0) + 1;
                    await idbUpdatePendingSW(row.id, row.data);
                } catch (_) {}
                remaining++;
            }
        }
        // Notify clients of sync result
        try {
            const allClients = await self.clients.matchAll({ includeUncontrolled: true });
            for (const client of allClients) {
                client.postMessage({ type: 'autosave-sync-result', successes, remaining });
            }
        } catch (_) {}
        // If there are remaining items, re-register sync to retry later
        if (remaining > 0 && self.registration && self.registration.sync) {
            try { await self.registration.sync.register('autosave-sync'); } catch (_) {}
        }
    } catch (e) {
        // ignore
    }
}

// Helper to update pending item by id in SW (since put wasn't defined earlier)
async function idbUpdatePendingSW(id, data) {
    try {
        const db = await idbOpenSW();
        return new Promise((resolve, reject) => {
            const tx = db.transaction('autosaves', 'readwrite');
            const store = tx.objectStore('autosaves');
            const req = store.get(id);
            req.onsuccess = () => {
                const existing = req.result || {};
                const updated = Object.assign({}, existing, { id, data });
                const putReq = store.put(updated);
                putReq.onsuccess = () => resolve();
                putReq.onerror = () => reject(putReq.error);
            };
            req.onerror = () => reject(req.error);
        });
    } catch (e) {
        // ignore
    }
}

self.addEventListener('sync', (event) => {
    if (event.tag === 'autosave-sync') {
        event.waitUntil(flushPendingFromSW());
    }
});
