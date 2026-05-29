// Service Worker pentru DepanAuto Client PWA
// Strategie: network-first pentru navigare/HTML (asa update-urile ajung la useri),
// cache-first pentru asset-uri statice (icons/manifest). API-ul ramane mereu live.
const CACHE_NAME = 'depanauto-client-v1';

const CACHE_FILES = [
    '/depanauto/client/',
    '/depanauto/client/index.html',
    '/depanauto/client/manifest.json',
    '/depanauto/icons/icon-192.png',
    '/depanauto/icons/icon-512.png'
];

self.addEventListener('install', event => {
    event.waitUntil(caches.open(CACHE_NAME).then(cache => cache.addAll(CACHE_FILES)));
    self.skipWaiting();
});

self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;
    if (req.url.includes('/depanauto/api/')) return; // API mereu live

    // Navigare / HTML -> network-first cu fallback la cache (update-uri propagate imediat)
    if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(req)
                .then(res => {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then(c => c.put(req, copy)).catch(()=>{});
                    return res;
                })
                .catch(() => caches.match(req).then(c => c || caches.match('/depanauto/client/')))
        );
        return;
    }

    // Asset-uri statice -> cache-first
    event.respondWith(caches.match(req).then(cached => cached || fetch(req)));
});
