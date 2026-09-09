/* AirKing PWA — minimal offline shell for SPA (Vercel / CRA). */
// Bump this whenever the cached shell (index.html / manifest.json) changes,
// otherwise returning users keep serving the previous shell forever.
const CACHE_NAME = 'airking-pwa-shell-v2';

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) =>
      cache.addAll(['./index.html', './manifest.json']).catch(() => {})
    )
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches
      .keys()
      .then((keys) =>
        Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
      )
      .then(() => self.clients.claim())
  );
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
