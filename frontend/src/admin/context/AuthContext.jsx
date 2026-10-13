import { createContext, useContext, useState, useCallback, useEffect, useRef } from 'react';
import { removeToken, authAPI, permissionsAPI } from '../services/api';
import {
  shouldRefreshPermissions,
  readCachedPermissions,
  writeCachedPermissions,
  clearCachedPermissions,
  PERMISSIONS_TTL_MS,
} from '../utils/permissionCache';

const AuthContext = createContext(null);

const readStoredUser = () => {
  try {
    return JSON.parse(sessionStorage.getItem('user') || 'null');
  } catch {
    return null;
  }
};

export const AuthProvider = ({ children }) => {
  const [user, setUser] = useState(readStoredUser);
  const [permissions, setPermissions] = useState(() => readCachedPermissions()?.map ?? null);
  const lastPermFetchRef = useRef(readCachedPermissions()?.at ?? null);

  const updateUser = useCallback((userData) => {
    setUser(userData);
    if (userData) {
      sessionStorage.setItem('user', JSON.stringify(userData));
    } else {
      sessionStorage.removeItem('user');
    }
  }, []);

  const clearSession = useCallback(() => {
    // Dynamic import so laravel-echo/pusher stay out of the initial JS bundle.
    import('../utils/echo').then((m) => m.disconnectEcho()).catch(() => {});
    updateUser(null);
    clearCachedPermissions();
    lastPermFetchRef.current = null;
    setPermissions(null);
    removeToken();
  }, [updateUser]);

  /**
   * Re-fetches the authenticated user from GET /api/user and updates the
   * stored session. This hydrates the location enforcement fields
   * (enforced_location_id, allowed_locations, has_strict_location, etc.)
   * that are not present in the login response.
   */
  const refreshUser = useCallback(async () => {
    try {
      const res = await authAPI.getUser();
      // Handle both response shapes: { success, data } and legacy { user }
      const fresh = res?.data ?? res?.user ?? null;
      if (fresh) updateUser(fresh);
      return fresh;
    } catch {
      return null;
    }
  }, [updateUser]);

  // eslint-disable-next-line react-hooks/exhaustive-deps
  const refreshPermissions = useCallback((opts = {}) => {
    if (!user?.user_id) return;
    const now = Date.now();
    if (!opts.force && !shouldRefreshPermissions(lastPermFetchRef.current, now, PERMISSIONS_TTL_MS)) {
      return;
    }
    const prevFetchedAt = lastPermFetchRef.current;
    lastPermFetchRef.current = now;
    permissionsAPI.getMine()
      .then((res) => {
        const map = res?.data ?? {};
        setPermissions(map);
        writeCachedPermissions(map, now);
      })
      .catch(() => {
        // Keep the cached map, but don't let a failed attempt suppress retries:
        // only roll back if no newer fetch updated the timestamp meanwhile.
        if (lastPermFetchRef.current === now) {
          lastPermFetchRef.current = prevFetchedAt;
        }
      });
  }, [user?.user_id]);

  useEffect(() => {
    if (!user?.user_id) {
      clearCachedPermissions();
      lastPermFetchRef.current = null;
      setPermissions(null);
      return;
    }
    // Prefer warm session cache on first paint; only force-refresh when stale.
    const cached = readCachedPermissions();
    if (cached?.map && permissions == null) {
      setPermissions(cached.map);
      lastPermFetchRef.current = cached.at ?? Date.now();
    }
    refreshPermissions({ force: !cached?.map });
    const onFocus = () => refreshPermissions();
    window.addEventListener('focus', onFocus);
    return () => window.removeEventListener('focus', onFocus);
  }, [user?.user_id]); // eslint-disable-line react-hooks/exhaustive-deps

  const can = useCallback(
    (feature, action) =>
      permissions != null && permissions[`${feature}.${action}`] === true,
    [permissions],
  );

  return (
    <AuthContext.Provider value={{ user, updateUser, refreshUser, clearSession, permissions, can, refreshPermissions }}>
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = () => {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider');
  return ctx;
};

/**
 * Convenience hook — returns resolved location data for the current user.
 * Works with both login-populated users (fallback from location_id column)
 * and refreshUser()-hydrated users (full enforced_location_id fields).
 */
export const useUserLocation = () => {
  const { user } = useAuth();
  const roleId   = Number(user?.role?.role_id ?? 0);
  const roleName = user?.role?.role_name ?? '';

  // Two separate boundaries, and for the branch manager they disagree:
  //
  //   isStrictLocation  — WRITE scope. True for branch_manager=3 and
  //                       warehouse_personnel=4: the server pins the location
  //                       on every write, so there is nothing to pick.
  //   readsAllLocations — READ scope. True for admin=1, inventory_analyst=2,
  //                       branch_manager=3 and auditor=5: these roles see every
  //                       location's data, so a screen must not filter to one.
  //
  // The branch manager is both: it reads across branches but transacts only at
  // its own. Prefer the server-computed flags (mirrors User::hasStrictLocation()
  // and User::readsAllLocations()); the role_id lists are only the fallback for
  // a user object that login populated but refreshUser() has not hydrated yet.
  const isStrictLocation  = user?.has_strict_location ?? [3, 4].includes(roleId);
  const readsAllLocations = user?.reads_all_locations ?? [1, 2, 3, 5].includes(roleId);

  // Admin picks the location it transacts at; everyone else either is pinned to
  // one or does not transact. Unchanged by the read-scope widening: the branch
  // manager used to be named here but was never reached, because a strict role
  // fails the first clause — its wider reach is visibility, not the authority to
  // post a movement at another branch.
  const canChooseLocation = !isStrictLocation && (roleName === 'admin' || roleId === 1);

  return {
    // The single location_id this user must transact at (null for admin = must pick)
    locationId:        user?.enforced_location_id ?? user?.location_id ?? null,
    locationName:      user?.primary_location?.location_name ?? user?.location?.location_name ?? null,
    locationType:      user?.primary_location?.location_type  ?? user?.location?.location_type  ?? null,
    isStrictLocation,
    readsAllLocations,
    // Populated after refreshUser() — array of { location_id, location_name, location_type, location_code }
    allowedLocations:  user?.allowed_locations ?? [],
    canChooseLocation,
  };
};
