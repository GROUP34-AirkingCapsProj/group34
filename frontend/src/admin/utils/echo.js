import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { getApiBaseUrl, getToken } from '../services/api';

// pusher-js must be globally available for Laravel Echo's Reverb/Pusher connector.
window.Pusher = Pusher;

let echoInstance = null;

/** Host for the Reverb socket: explicit override, else the same host the API
 *  resolves to (so LAN clients use the machine IP, not 127.0.0.1). */
function resolveReverbHost() {
  const override = process.env.REACT_APP_REVERB_HOST && String(process.env.REACT_APP_REVERB_HOST).trim();
  if (override) return override;
  try {
    return new URL(getApiBaseUrl()).hostname;
  } catch (_) {
    return '127.0.0.1';
  }
}

/**
 * Lazily build and return the shared Echo instance, or null if it cannot be
 * created (missing key / construction error). Callers must tolerate null and
 * fall back to polling.
 */
export function getEcho() {
  if (echoInstance) return echoInstance;
  try {
    const key = process.env.REACT_APP_REVERB_APP_KEY && String(process.env.REACT_APP_REVERB_APP_KEY).trim();
    if (!key) return null;

    const host = resolveReverbHost();
    const port = Number(process.env.REACT_APP_REVERB_PORT || 8080);
    const scheme = String(process.env.REACT_APP_REVERB_SCHEME || 'http').toLowerCase();
    const forceTLS = scheme === 'https';

    echoInstance = new Echo({
      broadcaster: 'reverb',
      key,
      wsHost: host,
      wsPort: port,
      wssPort: port,
      forceTLS,
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${getApiBaseUrl()}/broadcasting/auth`,
      auth: {
        headers: {
          Authorization: `Bearer ${getToken()}`,
          Accept: 'application/json',
        },
      },
    });
    return echoInstance;
  } catch (_) {
    echoInstance = null;
    return null;
  }
}

/** Tear down the socket (e.g. on logout) and clear the singleton. */
export function disconnectEcho() {
  try {
    if (echoInstance && typeof echoInstance.disconnect === 'function') {
      echoInstance.disconnect();
    }
  } catch (_) {
    // ignore
  } finally {
    echoInstance = null;
  }
}
