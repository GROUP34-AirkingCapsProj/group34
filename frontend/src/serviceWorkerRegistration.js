// Registers the PWA shell worker (public/service-worker.js).
//
// The previous version registered and walked away. That is the usual advice and
// it is wrong for this app: the service worker caches index.html, and this app's
// configuration -- REACT_APP_API_PUBLIC_URL, the REVERB_* values -- is injected
// into index.html at build time. A browser holding yesterday's shell therefore
// holds yesterday's API URL, and keeps holding it, because a worker that is
// already installed only updates when something asks it to.
//
// So this does three extra things, each fixing a way the old shell survived:
//   1. updateViaCache: 'none' -- the browser's HTTP cache may not serve the
//      worker script itself, which is how a superseded worker stayed installed.
//   2. registration.update() on every load -- actively check for a new worker
//      rather than waiting for the browser's own schedule.
//   3. reload once on controllerchange -- when a new worker takes over, the page
//      currently on screen was rendered from the old shell. Without the reload
//      the user keeps the stale configuration until they happen to navigate.

let reloading = false;

export function register() {
  if (process.env.NODE_ENV !== 'production' || !('serviceWorker' in navigator)) {
    return;
  }

  // skipWaiting() in the worker means a new one activates immediately and
  // replaces the controller. That swap is the signal that the page on screen was
  // built from a shell that is no longer current.
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    // Guarded: controllerchange can fire more than once, and an unguarded
    // reload here is an infinite refresh loop.
    if (reloading) return;
    reloading = true;
    window.location.reload();
  });

  window.addEventListener('load', () => {
    const url = `${process.env.PUBLIC_URL || ''}/service-worker.js`;

    navigator.serviceWorker
      .register(url, { updateViaCache: 'none' })
      .then((registration) => {
        // Cheap, and the only thing that dislodges a worker installed by an
        // earlier deploy on a browser that never closed the tab.
        registration.update().catch(() => {
          /* offline, or the worker is already current */
        });
      })
      .catch(() => {
        /* non-fatal: the app works without it, just without the offline shell */
      });
  });
}
