export const PERMISSIONS_CACHE_KEY = 'permissions_cache';
export const PERMISSIONS_TTL_MS = 60000;

/**
 * @param {number|null} lastFetchedAt epoch ms of the last successful fetch (or null)
 * @param {number} now epoch ms now
 * @param {number} ttlMs freshness window
 * @returns {boolean} true if a background refresh should run
 */
export const shouldRefreshPermissions = (lastFetchedAt, now, ttlMs = PERMISSIONS_TTL_MS) => {
  if (lastFetchedAt == null) return true;
  return now - lastFetchedAt >= ttlMs;
};

/** @returns {{ map: object, at: number } | null} */
export const readCachedPermissions = () => {
  try {
    const raw = sessionStorage.getItem(PERMISSIONS_CACHE_KEY);
    const parsed = raw ? JSON.parse(raw) : null;
    if (parsed && typeof parsed.map === 'object') return parsed;
  } catch (_) {}
  return null;
};

export const writeCachedPermissions = (map, at) => {
  try {
    sessionStorage.setItem(PERMISSIONS_CACHE_KEY, JSON.stringify({ map, at }));
  } catch (_) {}
};

export const clearCachedPermissions = () => {
  try { sessionStorage.removeItem(PERMISSIONS_CACHE_KEY); } catch (_) {}
};
