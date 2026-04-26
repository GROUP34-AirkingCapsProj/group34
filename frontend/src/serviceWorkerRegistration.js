export function register() {
  if (process.env.NODE_ENV !== 'production' || !('serviceWorker' in navigator)) {
    return;
  }
  window.addEventListener('load', () => {
    const url = `${process.env.PUBLIC_URL || ''}/service-worker.js`;
    navigator.serviceWorker
      .register(url)
      .then(() => {
        /* optional: console.log('SW registered'); */
      })
      .catch(() => {
        /* non-fatal */
      });
  });
}
