// Service Worker pentru DepanAuto Depanator PWA
const CACHE_NAME = 'depanauto-depanator-v2';
const API_BASE = 'https://wsdlogistics.ro/depanauto/api';

// Fisiere de cacheat la instalare
const CACHE_FILES = [
    '/depanauto/depanator/',
    '/depanauto/depanator/index.html',
    '/depanauto/depanator/manifest.json',
    '/depanauto/icons/icon-192.png',
    '/depanauto/icons/icon-512.png'
];

// Instalare - cache fisiere de baza
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => cache.addAll(CACHE_FILES))
    );
    self.skipWaiting();
});

// Activare - sterge cache vechi
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys =>
            Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
        )
    );
    self.clients.claim();
});

// Fetch - network-first pentru HTML (update-uri propagate), cache-first pentru asset-uri
self.addEventListener('fetch', event => {
    const req = event.request;
    if (req.method !== 'GET') return;
    if (req.url.includes('/depanauto/api/')) return; // API mereu live

    if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
        event.respondWith(
            fetch(req)
                .then(res => {
                    const copy = res.clone();
                    caches.open(CACHE_NAME).then(c => c.put(req, copy)).catch(()=>{});
                    return res;
                })
                .catch(() => caches.match(req).then(c => c || caches.match('/depanauto/depanator/')))
        );
        return;
    }

    event.respondWith(caches.match(req).then(cached => cached || fetch(req)));
});

// Polling in background pentru cereri noi
let pollInterval = null;
let lastRequestCount = 0;

self.addEventListener('message', event => {
    if (event.data.type === 'START_POLLING' && event.data.token) {
        const token = event.data.token;
        if (pollInterval) clearInterval(pollInterval);
        
        pollInterval = setInterval(async () => {
            try {
                const res = await fetch(API_BASE + '/get_status.php?token=' + token);
                const data = await res.json();
                if (!data.success) return;

                const waitingCount = (data.waiting_requests || []).length;
                
                // Cerere noua aparuta
                if (waitingCount > 0 && lastRequestCount === 0) {
                    self.registration.showNotification('🔧 Cerere nouă!', {
                        body: 'Un client are nevoie de ajutor. Apasă pentru detalii.',
                        icon: '/depanauto/icons/icon-192.png',
                        badge: '/depanauto/icons/icon-192.png',
                        vibrate: [200, 100, 200, 100, 200],
                        requireInteraction: true,
                        tag: 'new-request',
                        data: { url: '/depanauto/depanator/' }
                    });
                }
                lastRequestCount = waitingCount;
            } catch(e) {}
        }, 5000); // Polling la 5 secunde in background
    }
    
    if (event.data.type === 'STOP_POLLING') {
        if (pollInterval) clearInterval(pollInterval);
        lastRequestCount = 0;
    }
});

// Click pe notificare deschide aplicatia
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientList => {
            for (const client of clientList) {
                if (client.url.includes('/depanauto/depanator/') && 'focus' in client) {
                    return client.focus();
                }
            }
            return clients.openWindow('/depanauto/depanator/');
        })
    );
});
