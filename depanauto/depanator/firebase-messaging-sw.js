importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey: "AIzaSyCgjoICLRuq4nHlZcXWXwzzgLHM5qCnJI8",
    authDomain: "depanauto-8211a.firebaseapp.com",
    projectId: "depanauto-8211a",
    storageBucket: "depanauto-8211a.firebasestorage.app",
    messagingSenderId: "865681548860",
    appId: "1:865681548860:web:ef5c12182afd26b6f48539"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(function(payload) {
    const title = payload.notification?.title || '🔧 Cerere nouă!';
    const body  = payload.notification?.body  || 'Un client are nevoie de ajutor.';
    self.registration.showNotification(title, {
        body: body,
        icon: '/depanauto/icons/icon-192.png',
        badge: '/depanauto/icons/icon-192.png',
        vibrate: [200, 100, 200, 100, 200],
        requireInteraction: true,
        data: payload.data || {}
    });
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function(clientList) {
            for (const client of clientList) {
                if (client.url.includes('/depanauto/depanator/') && 'focus' in client) {
                    return client.focus();
                }
            }
            if (clients.openWindow) return clients.openWindow('/depanauto/depanator/');
        })
    );
});
