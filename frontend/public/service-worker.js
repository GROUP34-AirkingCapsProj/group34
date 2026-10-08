/* AirKing PWA — minimal offline shell for SPA (Vercel / CRA). */
//
// __BUILD_ID__ is replaced at build time by the `sw-build-id` plugin in
// vite.config.mjs. It used to be a hand-written 'v2' that someone had to
// remember to bump, and nobody did -- so returning users kept the shell from
// whichever deploy they first visited.
//
// That is worse here than for a typical PWA. The app's configuration
// (REACT_APP_API_PUBLIC_URL, the REVERB_* values) is injected into index.html at
// build time by the cra-process-env-shim, so a stale shell does not just look
// old: it points the app at the wrong API. A cached shell from before the
// production API URL was configured sends every request to 127.0.0.1, which is
// exactly how this was found.
const CACHE_NAME = 'airking-pwa-shell-__BUILD_ID__';

self.addEventListener('install', (event) => {
  self.skipWaiting();

  // Deliberately does NOT pre-cache index.html.
  //
  // The old version did, which meant a worker installed during one deploy
  // planted that deploy's shell -- including its baked-in API URL -- and served
  // it as the offline fallback indefinitely. index.html is now only cached after
  // a successful network fetch (see below), so the fallback can never be older
  // than the last page the user actually loaded.
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(['./manifest.json']).catch(() => {}))
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
    // Network-first, and `cache: 'reload'` so the browser's own HTTP cache
    // cannot hand back a stale index.html before the service worker ever sees
    // it. Being network-first in the worker is not enough on its own -- there
    // are two caches in front of this document, not one.
    //
    // Guarded because this runs on EVERY navigation: the Request constructor
    // has to downgrade a navigate-mode request to same-origin, and a browser
    // that refuses instead of downgrading would throw here and break page loads
    // entirely. Falling back to the original request loses the cache override,
    // not the page.
    let documentRequest;
    try {
      documentRequest = new Request(request, { cache: 'reload' });
    } catch {
      documentRequest = request;
    }

    event.respondWith(
      fetch(documentRequest)
        .then((response) => {
          try {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put('./index.html', copy));
          } catch {
            /* ignore */
          }
          return response;
        })
        // Only reached when the network genuinely fails, i.e. offline.
        .catch(() => caches.match('./index.html').then((hit) => hit || caches.match('/index.html')))
    );
  }
});
