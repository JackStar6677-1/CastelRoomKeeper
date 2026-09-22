const CACHE_NAME = 'ccg-admin-calendar-pwa-v22';
const STATIC_ASSETS = [
  '/admin/offline.html',
  '/admin/calendar-icon.svg',
  '/admin/calendar.css?v=4',
  '/admin/calendar_month_app.js?v=40',
  '/admin/castel-theme.js',
  '/assets/LogoCastelGandolfoSinFondo.png',
  '/assets/castel-app-icon.png'
];

function isPrivateAdminRequest(url) {
  return url.pathname.endsWith('.php') || url.pathname.includes('/admin/calendar_api.php');
}

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(STATIC_ASSETS.map((url) => new Request(url, { cache: 'reload' }))))
      .catch(() => null)
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((key) => (key === CACHE_NAME ? null : caches.delete(key)))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;

  if (request.mode === 'navigate' || isPrivateAdminRequest(url)) {
    event.respondWith(
      fetch(request).catch(() => {
        if (request.mode === 'navigate') {
          return caches.match('/admin/offline.html');
        }
        return new Response(JSON.stringify({ ok: false, message: 'Sin conexion.' }), {
          status: 503,
          headers: { 'Content-Type': 'application/json; charset=utf-8' }
        });
      })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      const network = fetch(new Request(request, { cache: 'reload' }))
        .then((response) => {
          if (response && response.status === 200) {
            caches.open(CACHE_NAME).then((cache) => cache.put(request, response.clone())).catch(() => null);
          }
          return response;
        })
        .catch(() => cached || caches.match('/admin/calendar-icon.svg'));
      return cached || network;
    })
  );
});
// --- Web Push: muestra la notificación aunque el calendario esté cerrado ---
// (Requiere que el servidor envíe el push con VAPID; si no hay envío, no se dispara.)
self.addEventListener('push', (event) => {
  let payload = { title: 'Sala de computación', body: 'Tienes una novedad en el calendario.' };
  try {
    if (event.data) {
      const parsed = event.data.json();
      payload = Object.assign(payload, parsed || {});
    }
  } catch (e) {
    try { payload.body = event.data.text() || payload.body; } catch (e2) {}
  }
  event.waitUntil(
    self.registration.showNotification(payload.title, {
      body: payload.body,
      tag: payload.tag || 'castel-push',
      icon: '/admin/calendar-icon.svg',
      badge: '/admin/calendar-icon.svg',
      data: { url: payload.url || '/admin/' }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = (event.notification.data && event.notification.data.url) || '/admin/';
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
      for (const client of list) {
        if (client.url.includes('/admin') && 'focus' in client) return client.focus();
      }
      return self.clients.openWindow ? self.clients.openWindow(target) : null;
    })
  );
});
