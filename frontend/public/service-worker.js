/* AirKing PWA — minimal offline shell for SPA (Vercel / CRA). */
const CACHE_NAME = 'airking-pwa-shell-v1';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      cache.addAll(['./index.html', './manifest.json']).catch(() => {})
    )
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  const { request } = event;
  if (request.method !== 'GET') return;

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          try {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put('./index.html', copy));
          } catch {
            /* ignore */
          }
          return response;
        })
        .catch(() => caches.match('./index.html') || caches.match('/index.html'))
    );
    return;
  }
});
