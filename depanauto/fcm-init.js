// fcm-init.js — înregistrare token Firebase Cloud Messaging pentru notificări push.
//
// ⚠️ CONFIGURARE NECESARĂ ÎNAINTE DE A FUNCȚIONA:
//   Pune mai jos cheia VAPID (Web Push certificate) din
//   Firebase Console → Project settings → Cloud Messaging → "Web Push certificates".
//   Cât timp FCM_VAPID_KEY este gol, push-ul este DEZACTIVAT și initPush() nu face nimic
//   (aplicația funcționează normal, fără riscuri).
const FCM_VAPID_KEY = ''; // <-- COMPLETEAZĂ cu cheia VAPID publică

const FCM_CONFIG = {
  apiKey: "AIzaSyCgjoICLRuq4nHlZcXWXwzzgLHM5qCnJI8",
  authDomain: "depanauto-8211a.firebaseapp.com",
  projectId: "depanauto-8211a",
  storageBucket: "depanauto-8211a.firebasestorage.app",
  messagingSenderId: "865681548860",
  appId: "1:865681548860:web:ef5c12182afd26b6f48539"
};

function _fcmLoadScript(src) {
  return new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = src; s.onload = resolve; s.onerror = reject;
    document.head.appendChild(s);
  });
}

// Înregistrează tokenul push al utilizatorului curent. Best-effort, nu aruncă erori.
// apiBase: URL-ul API; swPath: calea către firebase-messaging-sw.js; token: tokenul de cont.
async function initPush(apiBase, swPath, token) {
  try {
    if (!FCM_VAPID_KEY) return;                          // push dezactivat până se pune cheia VAPID
    if (!token) return;
    if (!('serviceWorker' in navigator) || !('Notification' in window)) return;

    const perm = await Notification.requestPermission();
    if (perm !== 'granted') return;

    if (typeof firebase === 'undefined') {
      await _fcmLoadScript('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
      await _fcmLoadScript('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');
    }
    if (!firebase.apps.length) firebase.initializeApp(FCM_CONFIG);

    // Scope dedicat ca să NU intre în conflict cu service worker-ul de cache (sw.js),
    // care controlează scope-ul directorului.
    const scope = swPath.replace(/[^/]*$/, '') + 'fcm-scope/';
    const reg = await navigator.serviceWorker.register(swPath, { scope });
    const messaging = firebase.messaging();
    const fcmToken = await messaging.getToken({ vapidKey: FCM_VAPID_KEY, serviceWorkerRegistration: reg });
    if (!fcmToken) return;

    await fetch(apiBase + '/save_fcm_token.php', {
      method: 'POST',
      body: new URLSearchParams({ token: token, fcm_token: fcmToken })
    });
  } catch (e) { /* push best-effort */ }
}
